<?php

namespace App\Domain\Employee\Import;

use App\Domain\Access\DataScope;
use App\Domain\Employee\Enums\Gender;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\NikCipher;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Position;
use App\Domain\Organization\Support\LocationScope;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Http\Requests\Employee\EmployeeRequest;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Validasi satu baris impor (BR-054) setelah dipetakan. Daftar referensi dimuat SEKALI per batch
 * (bukan per baris) karena berkas bisa ribuan baris. Aturan panjang/umur sama dengan form pegawai
 * (`EmployeeRequest`) supaya data impor tidak lebih longgar dari input manual.
 *
 * Lokasi dan atasan harus dalam scope pengunggah; di luar scope diperlakukan "tidak ditemukan"
 * agar keberadaannya tidak bocor (SEC-033).
 */
final class RowValidator
{
    private const NUMBER_PATTERN = '/^[A-Za-z0-9.\/_-]{1,30}$/';

    /** @var array{code: array<string, list<int>>, name: array<string, list<int>>, label: array<int, string>} */
    private array $statuses;

    /** @var array{code: array<string, list<int>>, name: array<string, list<int>>, label: array<int, string>} */
    private array $locations;

    /** @var array{code: array<string, list<int>>, name: array<string, list<int>>, label: array<int, string>} */
    private array $positions;

    /** @var array<string, int> nomor pegawai (huruf kecil) → id, pegawai aktif dalam scope */
    private array $supervisors;

    /** @var array<string, true> nomor pegawai (huruf kecil) yang ada di berkas yang sama */
    private array $numbersInFile;

    private string $today;

    /** @param  list<string>  $numbersInFile */
    public function __construct(User $actor, array $numbersInFile = [])
    {
        $this->today = DisplayTime::toLocal(now())->toDateString();

        $this->statuses = self::index(ReferenceItem::query()->forGroup(ReferenceGroup::EmploymentStatus)->where('is_active', true));

        $locations = Location::query()->where('is_active', true);
        app(LocationScope::class)->apply($locations, $actor, 'locations.id');
        $this->locations = self::index($locations);

        $this->positions = self::index(Position::query()->where('is_active', true));

        $supervisors = Employee::query()->where('status', 'active');
        app(DataScope::class)->apply($supervisors, $actor, 'employees.id');
        $this->supervisors = [];
        foreach ($supervisors->pluck('id', 'employee_number') as $number => $id) {
            $this->supervisors[mb_strtolower((string) $number)] = (int) $id;
        }

        $this->numbersInFile = array_fill_keys(array_map(mb_strtolower(...), $numbersInFile), true);
    }

    /**
     * @param  array<string, mixed>  $raw  field terpetakan => sel mentah
     * @return array{data: array<string, mixed>, errors: array<string, list<string>>, key: string|null}
     */
    public function validate(array $raw): array
    {
        $data = [];
        $ids = [];
        $errors = [];
        $fail = function (string $field, string $message) use (&$errors) {
            $errors[$field][] = $message;
        };
        $mapped = fn (string $field) => array_key_exists($field, $raw);

        // Nilai yang lolos normalisasi disimpan ternormalisasi; yang gagal disimpan apa adanya
        // (untuk ditampilkan merah), kecuali NIK yang tidak pernah disimpan utuh (SEC-010).
        $take = function (string $field, callable $normalize) use ($raw, $mapped, &$data, $fail): mixed {
            if (! $mapped($field)) {
                return null;
            }
            try {
                $value = $normalize($raw[$field]);
            } catch (InvalidArgumentException $e) {
                $fail($field, $e->getMessage());
                $data[$field] = $field === 'nik' ? null : RowNormalizer::text($raw[$field]);

                return null;
            }
            if ($value === null && EmployeeImportFields::FIELDS[$field]['required']) {
                $fail($field, 'Wajib diisi.');
            }
            $data[$field] = $value;

            return $value;
        };

        $name = $take('full_name', RowNormalizer::name(...));
        if (is_string($name) && (mb_strlen($name) < 2 || mb_strlen($name) > 150)) {
            $fail('full_name', 'Nama 2–150 karakter.');
        }

        $gender = $take('gender', RowNormalizer::gender(...));
        $data['gender'] = $gender instanceof Gender ? $gender->value : ($data['gender'] ?? null);

        $join = $take('join_date', RowNormalizer::date(...));
        if (is_string($join) && $join > $this->today) {
            $fail('join_date', 'Tanggal masuk tidak boleh melewati hari ini.');
        }

        $birth = $take('birth_date', RowNormalizer::date(...));
        if (is_string($birth) && $birth >= $this->today) {
            $fail('birth_date', 'Tanggal lahir harus sebelum hari ini.');
        } elseif (is_string($birth) && is_string($join)) {
            $age = (int) floor(CarbonImmutable::parse($birth)->diffInYears(CarbonImmutable::parse($join), absolute: false));
            if ($age < EmployeeRequest::AGE_MIN || $age > EmployeeRequest::AGE_MAX) {
                $fail('birth_date', 'Umur pada tanggal masuk harus '.EmployeeRequest::AGE_MIN.'–'.EmployeeRequest::AGE_MAX.' tahun.');
            }
        }

        $number = $take('employee_number', RowNormalizer::code(...));
        if (is_string($number) && preg_match(self::NUMBER_PATTERN, $number) !== 1) {
            $fail('employee_number', 'No. pegawai maks. 30 karakter: huruf, angka, titik, garis miring, strip.');
            $number = null;
        }

        $nik = $take('nik', RowNormalizer::nik(...));
        $data['nik'] = is_string($nik) ? NikCipher::mask($nik) : null;

        $take('phone', RowNormalizer::phone(...));
        $take('email', RowNormalizer::email(...));

        $address = $take('address', RowNormalizer::text(...));
        if (is_string($address) && mb_strlen($address) > 500) {
            $fail('address', 'Alamat maks. 500 karakter.');
        }

        $ids['employment_status_id'] = $this->reference('employment_status', $raw, $data, $fail, $this->statuses, 'Status kerja');
        $ids['location_id'] = $this->reference('location', $raw, $data, $fail, $this->locations, 'Lokasi');
        $ids['position_id'] = $this->reference('position', $raw, $data, $fail, $this->positions, 'Jabatan');

        $supervisor = $take('supervisor_number', RowNormalizer::code(...));
        if (is_string($supervisor)) {
            $key = mb_strtolower($supervisor);
            if ($number !== null && $key === mb_strtolower($number)) {
                $fail('supervisor_number', 'Atasan tidak boleh pegawai itu sendiri.');
            } elseif (isset($this->supervisors[$key])) {
                $ids['supervisor_employee_id'] = $this->supervisors[$key];
            } elseif (! isset($this->numbersInFile[$key])) {
                // Atasan yang ada di berkas yang sama dibuat lebih dulu saat proses (T-028).
                $fail('supervisor_number', 'Atasan tidak ditemukan di data pegawai aktif maupun di berkas ini.');
            }
        }

        $data['ids'] = array_filter($ids, fn ($id) => $id !== null);

        return [
            'data' => $data,
            'errors' => $errors,
            'key' => RowKey::for(is_string($nik) ? $nik : null, $number, is_string($name) ? $name : null, is_string($birth) ? $birth : null),
        ];
    }

    /**
     * Cocokkan kode atau nama (tidak peka huruf besar). Nama yang dipakai lebih dari satu data
     * dianggap ambigu: pengguna diminta memakai kode.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $data
     * @param  array{code: array<string, list<int>>, name: array<string, list<int>>, label: array<int, string>}  $index
     */
    private function reference(string $field, array $raw, array &$data, callable $fail, array $index, string $label): ?int
    {
        if (! array_key_exists($field, $raw)) {
            return null;
        }
        $text = RowNormalizer::text($raw[$field]);
        $data[$field] = $text;
        if ($text === null) {
            $fail($field, 'Wajib diisi.');

            return null;
        }

        $key = mb_strtolower($text);
        $ids = $index['code'][$key] ?? $index['name'][$key] ?? [];
        if (count($ids) > 1) {
            $fail($field, "{$label} \"{$text}\" ada lebih dari satu. Gunakan kodenya.");

            return null;
        }
        if ($ids === []) {
            $fail($field, "{$label} \"{$text}\" tidak ditemukan atau tidak aktif.");

            return null;
        }

        $data[$field] = $index['label'][$ids[0]];

        return $ids[0];
    }

    /**
     * @param  Builder<*>  $query
     * @return array{code: array<string, list<int>>, name: array<string, list<int>>, label: array<int, string>}
     */
    private static function index(Builder $query): array
    {
        $index = ['code' => [], 'name' => [], 'label' => []];
        foreach ($query->get(['id', 'code', 'name']) as $row) {
            $id = (int) $row->getAttribute('id');
            $code = (string) $row->getAttribute('code');
            $name = (string) $row->getAttribute('name');
            if ($code !== '') {
                $index['code'][mb_strtolower($code)][] = $id;
            }
            $index['name'][mb_strtolower($name)][] = $id;
            $index['label'][$id] = $name;
        }

        return $index;
    }
}
