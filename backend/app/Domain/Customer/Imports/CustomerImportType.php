<?php

namespace App\Domain\Customer\Imports;

use App\Domain\Access\DataScope;
use App\Domain\Customer\Actions\ActivateImportedCustomer;
use App\Domain\Customer\Actions\CreateCustomer;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\SalesArea;
use App\Domain\Customer\Support\PhoneConflicts;
use App\Domain\Pricing\Models\PriceList;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Http\Requests\Customer\CustomerRequest;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Import\Actions\ProcessImportBatch;
use App\Support\Import\AppliedRow;
use App\Support\Import\Cells;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportFields;
use App\Support\Import\ImportOrder;
use App\Support\Import\ImportPhase;
use App\Support\Import\ImportRow;
use App\Support\Import\ImportRowStatus;
use App\Support\Import\ImportType;
use App\Support\Import\NormalizedRow;
use App\Support\Import\ValidatedRow;
use App\Support\Phone\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * Impor master kios (T-085, FR-A30, BR-170, BR-165/166). Kunci idempoten = HP pemilik ternormalisasi
 * (`62…`). Kios baru selalu lewat `CreateCustomer` (cek duplikat & HP pegawai sama dengan layar).
 * Kios yang HP-nya sudah terdaftar dilewati, tidak ditimpa (ubah HP/data kios punya alurnya sendiri,
 * PA-70). Kolom `sudah_terverifikasi=ya` + pengimpor memegang `customers.verify` + konfirmasi password
 * saat proses → kios langsung `active` (cutover, ActivateImportedCustomer); selain itu `prospect`.
 */
final class CustomerImportType implements ImportType
{
    public const KEY = 'customers';

    public function __construct(
        private readonly CreateCustomer $create,
        private readonly ActivateImportedCustomer $activate,
        private readonly PhoneConflicts $phones,
        private readonly DataScope $scope,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Kios';
    }

    public static function routeSlug(): string
    {
        return 'kios';
    }

    public function permission(): string
    {
        return 'customers.manage';
    }

    /** Kios lama tidak ditimpa impor (PA-70): opsi "perbarui" tidak tersedia. */
    public function updatePermission(): ?string
    {
        return null;
    }

    public function fields(): ImportFields
    {
        return new ImportFields([
            'code' => ['label' => 'Kode Kios', 'required' => false, 'hint' => 'Kosong = dibuat otomatis', 'synonyms' => ['kode kios', 'kode pelanggan', 'kode toko', 'kode']],
            'store_name' => ['label' => 'Nama Toko', 'required' => true, 'hint' => '', 'synonyms' => ['nama toko', 'nama kios', 'toko', 'kios', 'nama pelanggan']],
            'owner_name' => ['label' => 'Nama Pemilik', 'required' => true, 'hint' => '', 'synonyms' => ['nama pemilik', 'pemilik']],
            'owner_phone' => ['label' => 'HP Pemilik', 'required' => true, 'hint' => 'mis. 0812-3456-7890', 'synonyms' => ['hp pemilik', 'no hp', 'nomor hp', 'hp', 'telepon', 'no telp', 'wa']],
            'customer_type' => ['label' => 'Jenis', 'required' => true, 'hint' => 'Kode/nama jenis pelanggan', 'synonyms' => ['jenis', 'jenis pelanggan', 'tipe']],
            'sales_area' => ['label' => 'Kode Area', 'required' => true, 'hint' => 'Kode area penjualan', 'synonyms' => ['kode area', 'area', 'area penjualan', 'wilayah']],
            'village_code' => ['label' => 'Kode Desa', 'required' => false, 'hint' => 'mis. 33.19.01.2001', 'synonyms' => ['kode desa', 'desa', 'kelurahan']],
            'address' => ['label' => 'Alamat', 'required' => false, 'hint' => '', 'synonyms' => ['alamat', 'alamat toko']],
            'term_days' => ['label' => 'Tempo (hari)', 'required' => false, 'hint' => 'Kosong = bawaan', 'synonyms' => ['tempo', 'termin', 'top', 'tempo hari']],
            'price_list' => ['label' => 'Kode Daftar Harga', 'required' => false, 'hint' => 'Daftar harga jenis tingkat', 'synonyms' => ['kode daftar harga', 'daftar harga', 'price list']],
            'verified' => ['label' => 'Sudah Terverifikasi', 'required' => false, 'hint' => 'ya = aktif (butuh izin verifikasi + password)', 'synonyms' => ['sudah terverifikasi', 'terverifikasi', 'verifikasi', 'sudah_terverifikasi']],
        ]);
    }

    public function context(User $actor, ImportBatch $batch, ImportPhase $phase): object
    {
        $types = [];
        foreach (ReferenceItem::query()->forGroup(ReferenceGroup::CustomerType)->where('is_active', true)->get(['id', 'code', 'name']) as $t) {
            $types[mb_strtoupper($t->code)] = $t->id;
            $types[mb_strtoupper($t->name)] = $t->id;
        }
        $assignable = $this->scope->assignableSalesAreaIds($actor);

        return new CustomerImportContext(
            types: $types,
            areas: SalesArea::query()->where('is_active', true)
                ->when($assignable !== null, fn ($q) => $q->whereIn('id', $assignable === [] ? [0] : $assignable))
                ->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtoupper((string) $code) => (int) $id])->all(),
            priceLists: PriceList::query()->where('kind', 'tier')->where('is_active', true)
                ->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtoupper((string) $code) => (int) $id])->all(),
            canVerify: $actor->can('customers.verify'),
            passwordConfirmed: ProcessImportBatch::passwordConfirmed($batch),
            batchId: $batch->id,
        );
    }

    public function normalize(array $raw, object $context): NormalizedRow
    {
        $values = [];
        $data = [];
        $errors = [];
        $take = function (string $field, callable $parse) use ($raw, &$values, &$data, &$errors): void {
            if (! array_key_exists($field, $raw)) {
                return;
            }
            try {
                $data[$field] = $parse($raw[$field]);
                $values[$field] = is_bool($data[$field]) ? ($data[$field] ? 'ya' : 'tidak') : $data[$field];
            } catch (InvalidArgumentException $e) {
                $errors[$field][] = $e->getMessage();
                $values[$field] = Cells::text($raw[$field], 10_000);
            }
        };

        $take('code', Cells::code(...));
        $take('store_name', fn ($v) => Cells::text($v, 150));
        $take('owner_name', fn ($v) => Cells::text($v, 150));
        $take('owner_phone', function ($v) {
            if (Cells::text($v) === null) {
                return null;
            }
            $text = is_int($v) || is_float($v) ? sprintf('%.0f', $v) : ltrim((string) Cells::text($v), "'");

            return PhoneNormalizer::normalize($text) ?? throw new InvalidArgumentException('Nomor HP tidak valid. Gunakan nomor Indonesia, contoh 0812-3456-7890.');
        });
        $take('customer_type', Cells::code(...));
        $take('sales_area', Cells::code(...));
        $take('village_code', Cells::code(...));
        $take('address', fn ($v) => Cells::text($v, 1000));
        $take('term_days', fn ($v) => Cells::int($v, 365));
        $take('price_list', Cells::code(...));
        $take('verified', Cells::bool(...));

        foreach ($this->fields()->required() as $f) {
            if (($data[$f] ?? null) === null && ! isset($errors[$f])) {
                $errors[$f][] = 'Wajib diisi.';
            }
        }

        return new NormalizedRow($values, $data, $errors);
    }

    public function validate(NormalizedRow $row, object $context): ValidatedRow
    {
        $data = $row->data;
        $errors = $row->errors;
        $err = function (string $f, string $m) use (&$errors): void {
            $errors[$f][] = $m;
        };

        $code = $data['code'] ?? null;
        if (is_string($code) && preg_match('/^[A-Z0-9-]{3,20}$/', $code) !== 1) {
            $err('code', 'Kode kios 3–20 karakter: huruf besar, angka, dan strip.');
        }
        if (is_string($data['store_name'] ?? null) && mb_strlen($data['store_name']) < 2) {
            $err('store_name', 'Minimal 2 karakter.');
        }
        $typeId = is_string($data['customer_type'] ?? null) ? ($context->types[$data['customer_type']] ?? null) : null;
        if (is_string($data['customer_type'] ?? null) && $typeId === null) {
            $err('customer_type', 'Jenis pelanggan tidak dikenal.');
        }
        $areaId = is_string($data['sales_area'] ?? null) ? ($context->areas[$data['sales_area']] ?? null) : null;
        if (is_string($data['sales_area'] ?? null) && $areaId === null) {
            $err('sales_area', 'Kode area tidak dikenal, nonaktif, atau di luar wilayah Anda.');
        }
        $village = $data['village_code'] ?? null;
        if (is_string($village) && (preg_match(CustomerRequest::VILLAGE_PATTERN, $village) !== 1 || ! DB::table('villages')->where('code', $village)->exists())) {
            $err('village_code', 'Kode desa tidak dikenal (format 33.19.01.2001).');
        }
        $priceListId = is_string($data['price_list'] ?? null) ? ($context->priceLists[$data['price_list']] ?? null) : null;
        if (is_string($data['price_list'] ?? null) && $priceListId === null) {
            $err('price_list', 'Kode daftar harga tidak dikenal (harus daftar harga jenis tingkat yang aktif).');
        }

        $existingId = null;
        $phone = $data['owner_phone'] ?? null;
        if (is_string($phone)) {
            if ($this->phones->belongsToStaff($phone)) {
                $err('owner_phone', 'Nomor ini milik pegawai/pengguna, tidak boleh dipakai kios.');
            } else {
                $existingId = $this->phones->usedByCustomer($phone, null)?->id;
            }
        }
        if (is_string($code) && $existingId === null && Customer::withTrashed()->where('code', $code)->exists()) {
            $err('code', 'Kode kios sudah dipakai kios lain.');
        }

        return new ValidatedRow($row->values, [...Cells::display($row->values), 'parsed' => [
            ...$data,
            'customer_type_id' => $typeId,
            'sales_area_id' => $areaId,
            'price_list_id' => $priceListId,
            'existing_id' => $existingId,
            'verified' => ($data['verified'] ?? false) === true,
        ]], $errors);
    }

    public function rowKey(ValidatedRow $row): ?string
    {
        $phone = $row->data['parsed']['owner_phone'] ?? null;

        return is_string($phone) && $phone !== '' ? 'hp:'.$phone : null;
    }

    public function describeKey(string $key): string
    {
        return 'HP pemilik sama';
    }

    public function processOrder(array $raws): ImportOrder
    {
        return ImportOrder::asIs($raws);
    }

    public function apply(User $actor, ValidatedRow $row, array $raw, bool $updateExisting, object $context): AppliedRow
    {
        $d = $row->data['parsed'];
        $existing = $this->phones->usedByCustomer((string) $d['owner_phone'], null);
        if ($existing !== null) {
            return new AppliedRow(ImportRowStatus::Skipped, ['customer_id' => $existing->id, 'warnings' => ["HP sudah terdaftar di kios {$existing->code}; tidak diubah."]]);
        }

        try {
            $customer = $this->create->handle([
                'code' => $d['code'] ?? null,
                'store_name' => $d['store_name'],
                'owner_name' => $d['owner_name'],
                'owner_phone' => $d['owner_phone'],
                'customer_type_id' => $d['customer_type_id'],
                'sales_area_id' => $d['sales_area_id'],
                'village_code' => $d['village_code'] ?? null,
                'address' => $d['address'] ?? null,
                'term_days' => $d['term_days'] ?? (int) setting('customer.default_term_days'),
                'price_list_id' => $d['price_list_id'],
            ], $actor->id, keepCode: true);
        } catch (ApiError $e) {
            // HP bentrok yang lolos validasi (diubah di antaranya): galat baris, bukan batch gagal.
            throw ValidationException::withMessages(['owner_phone' => $e->publicMessage()]);
        }

        if (! $context instanceof CustomerImportContext) {
            throw new LogicException('Context impor kios tidak sah.');
        }
        $warnings = [];
        $activated = false;
        if ($d['verified'] === true) {
            if ($context->canVerify && $context->passwordConfirmed) {
                $this->activate->handle($customer, $actor, $context->batchId);
                $activated = true;
            } else {
                $warnings[] = 'Dibuat sebagai prospek: pengimpor tidak memegang izin verifikasi kios atau password tidak dikonfirmasi.';
            }
        }

        return new AppliedRow(ImportRowStatus::Created, ['customer_id' => $customer->id, 'warnings' => $warnings, 'activated' => $activated]);
    }

    public function committed(object $context, array $raw, AppliedRow $row): void {}

    public function errorField(string $key): string
    {
        return match ($key) {
            'customer_type_id' => 'customer_type',
            'sales_area_id' => 'sales_area',
            'price_list_id' => 'price_list',
            'not_duplicate', 'duplicate_reason' => 'store_name',
            default => $key,
        };
    }

    /**
     * Wajib password bila ada baris valid `sudah_terverifikasi=ya` DAN pengimpor memegang
     * `customers.verify` (tanpa izin itu kios tetap prospek, jadi tidak perlu password).
     */
    public function requiresPassword(ImportBatch $batch, User $actor): bool
    {
        return $actor->can('customers.verify')
            && ImportRow::query()->where('import_batch_id', $batch->id)->where('status', ImportRowStatus::Valid->value)->where('data->parsed->verified', true)->exists();
    }

    public function ensureAvailable(): void {}

    public function requiresBusinessDate(): bool
    {
        return false;
    }

    public function summary(ImportBatch $batch): array
    {
        return [];
    }
}
