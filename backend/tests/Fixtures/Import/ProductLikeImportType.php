<?php

namespace Tests\Fixtures\Import;

use App\Models\User;
use App\Support\Import\AppliedRow;
use App\Support\Import\CellText;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportFields;
use App\Support\Import\ImportOrder;
use App\Support\Import\ImportPhase;
use App\Support\Import\ImportRowStatus;
use App\Support\Import\ImportType;
use App\Support\Import\NormalizedRow;
use App\Support\Import\ValidatedRow;
use Illuminate\Validation\ValidationException;

/**
 * Tipe impor UJI (T-085A, BR-170): mirip saldo awal/stok — kunci gabungan lokasi+SKU+batch+bin yang
 * bisa melebihi 100 karakter. Dibuat HANYA dengan mengimplementasikan kontrak ImportType, tanpa
 * menyalin kode impor pegawai. "Data domain" disimpan di memori (statis) agar test tidak perlu DDL
 * (DDL MariaDB memicu commit implisit yang merusak transaksi RefreshDatabase).
 *
 * Didaftarkan lewat env IMPORT_TEST_TYPE di phpunit.xml (config `pakis.imports.uji_produk`).
 */
final class ProductLikeImportType implements ImportType
{
    public const KEY = 'uji_produk';

    public const PERMISSION = 'uji_produk.import';

    /** @var array<string, array<string, mixed>> kunci gabungan → data tersimpan */
    public static array $store = [];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Produk Uji';
    }

    public function routeSlug(): string
    {
        return 'uji-produk';
    }

    public function permission(): string
    {
        return self::PERMISSION;
    }

    public function updatePermission(): ?string
    {
        return null;
    }

    public function fields(): ImportFields
    {
        return new ImportFields([
            'location' => ['label' => 'Lokasi Stok', 'required' => true, 'hint' => 'Kode lokasi', 'synonyms' => ['lokasi stok', 'lokasi']],
            'sku' => ['label' => 'SKU', 'required' => true, 'hint' => 'Kode produk', 'synonyms' => ['sku', 'kode produk']],
            'batch' => ['label' => 'No. Batch', 'required' => true, 'hint' => '', 'synonyms' => ['no batch', 'batch']],
            'bin' => ['label' => 'Rak/Bin', 'required' => false, 'hint' => '', 'synonyms' => ['rak bin', 'bin', 'rak']],
            'qty' => ['label' => 'Jumlah', 'required' => true, 'hint' => 'Bilangan bulat ≥ 0', 'synonyms' => ['jumlah', 'qty']],
        ]);
    }

    public function context(User $actor, ImportBatch $batch, ImportPhase $phase): object
    {
        return new \stdClass;
    }

    public function normalize(array $raw, object $context): NormalizedRow
    {
        $values = [];
        $errors = [];
        foreach ($this->fields()->keys() as $field) {
            if (! array_key_exists($field, $raw)) {
                continue;
            }
            $text = CellText::of($raw[$field]);
            $values[$field] = $field === 'sku' && $text !== null ? mb_strtoupper($text) : $text;
            if ($text === null && $this->fields()->isRequired($field)) {
                $errors[$field][] = 'Wajib diisi.';
            }
        }

        return new NormalizedRow($values, $values, $errors);
    }

    public function validate(NormalizedRow $row, object $context): ValidatedRow
    {
        $errors = $row->errors;
        $qty = $row->values['qty'] ?? null;
        if (is_string($qty) && preg_match('/^\d+$/', $qty) !== 1) {
            $errors['qty'][] = 'Jumlah harus bilangan bulat ≥ 0.';
        }

        return new ValidatedRow($row->values, $row->data, $errors);
    }

    public function rowKey(ValidatedRow $row): ?string
    {
        $parts = array_map(fn (string $f) => (string) ($row->values[$f] ?? ''), ['location', 'sku', 'batch', 'bin']);

        return $parts[1] === '' ? null : implode('|', $parts);
    }

    public function describeKey(string $key): string
    {
        return 'lokasi, SKU, batch, dan rak sama';
    }

    public function processOrder(array $raws): ImportOrder
    {
        return ImportOrder::asIs($raws);
    }

    public function apply(User $actor, ValidatedRow $row, array $raw, bool $updateExisting, object $context): AppliedRow
    {
        $key = $this->rowKey($row) ?? throw ValidationException::withMessages(['_row' => 'SKU kosong.']);
        if (isset(self::$store[$key])) {
            return new AppliedRow(ImportRowStatus::Skipped, ['warnings' => ['Sudah ada; tidak diubah.']]);
        }
        self::$store[$key] = $row->values;

        return new AppliedRow(ImportRowStatus::Created, ['warnings' => []]);
    }

    public function committed(object $context, array $raw, AppliedRow $row): void {}

    public function errorField(string $key): string
    {
        return $key;
    }
}
