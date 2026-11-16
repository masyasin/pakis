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
use App\Support\Import\NormalizedRow;
use App\Support\Import\ValidatedRow;
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
     * Urutan field saat galat & data disusun. Sama dengan urutan pemeriksaan sebelum kontrak
     * ImportType dipecah (T-085A), agar pesan galat, berkas galat, dan `import_rows.data` identik.
     */
    private const ORDER = [
        'full_name', 'gender', 'join_date', 'birth_date', 'employee_number', 'nik', 'phone', 'email', 'address',
        'employment_status', 'location', 'position', 'supervisor_number',
    ];

    /** Field yang dicocokkan ke data referensi lewat kode atau nama. */
    private const REFERENCES = ['employment_status', 'location', 'position'];

    /**
     * Langkah 1 (`ImportType::normalize`): bentuk tiap sel (teks, tanggal, jenis kelamin, NIK, HP).
     * Nilai yang lolos disimpan ternormalisasi; yang gagal disimpan apa adanya (untuk ditampilkan
     * merah), kecuali NIK yang tidak pernah disimpan utuh (SEC-010).
     *
     * @param  array<string, mixed>  $raw  field terpetakan => sel mentah
     */
    public function normalize(array $raw): NormalizedRow
    {
        $values = [];
        $data = [];
        $errors = [];

        $take = function (string $field, callable $normalize) use ($raw, &$values, &$data, &$errors): void {
            if (! array_key_exists($field, $raw)) {
                return;
            }
            try {
                $value = $normalize($raw[$field]);
            } catch (InvalidArgumentException $e) {
                $errors[$field][] = $e->getMessage();
                $values[$field] = null;
                $data[$field] = $field === 'nik' ? null : RowNormalizer::text($raw[$field]);

                return;
            }
            if ($value === null && EmployeeImportFields::FIELDS[$field]['required']) {
                $errors[$field][] = 'Wajib diisi.';
            }
            $values[$field] = $value;
            $data[$field] = $value;
        };

        $take('full_name', RowNormalizer::name(...));
        $take('gender', RowNormalizer::gender(...));
        // Seperti sebelum dipecah: kunci `gender` & `nik` selalu ada di data (null bila tidak dipetakan).
        $data['gender'] = ($values['gender'] ?? null) instanceof Gender ? $values['gender']->value : ($data['gender'] ?? null);
        $take('join_date', RowNormalizer::date(...));
        $take('birth_date', RowNormalizer::date(...));
        $take('employee_number', RowNormalizer::code(...));
        $take('nik', RowNormalizer::nik(...));
        $data['nik'] = is_string($values['nik'] ?? null) ? NikCipher::mask($values['nik']) : null;
        $take('phone', RowNormalizer::phone(...));
        $take('email', RowNormalizer::email(...));
        $take('address', RowNormalizer::text(...));
        $take('supervisor_number', RowNormalizer::code(...));

        foreach (self::REFERENCES as $field) {
            if (! array_key_exists($field, $raw)) {
                continue;
            }
            $text = RowNormalizer::text($raw[$field]);
            $values[$field] = $text;
            $data[$field] = $text;
            if ($text === null) {
                $errors[$field][] = 'Wajib diisi.';
            }
        }

        return new NormalizedRow($values, $data, $errors);
    }

    /**
     * Langkah 2 (`ImportType::validate`): aturan panjang, tanggal, umur, referensi ber-scope, dan
     * atasan. Galat disusun per field dalam urutan ORDER: galat normalisasi dulu, lalu aturan ini.
     */
    public function check(NormalizedRow $row): ValidatedRow
    {
        $values = $row->values;
        $data = $row->data;
        $extra = [];
        $fail = function (string $field, string $message) use (&$extra): void {
            $extra[$field][] = $message;
        };

        $name = $values['full_name'] ?? null;
        if (is_string($name) && (mb_strlen($name) < 2 || mb_strlen($name) > 150)) {
            $fail('full_name', 'Nama 2–150 karakter.');
        }

        $join = $values['join_date'] ?? null;
        if (is_string($join) && $join > $this->today) {
            $fail('join_date', 'Tanggal masuk tidak boleh melewati hari ini.');
        }

        $birth = $values['birth_date'] ?? null;
        if (is_string($birth) && $birth >= $this->today) {
            $fail('birth_date', 'Tanggal lahir harus sebelum hari ini.');
        } elseif (is_string($birth) && is_string($join)) {
            $age = (int) floor(CarbonImmutable::parse($birth)->diffInYears(CarbonImmutable::parse($join), absolute: false));
            if ($age < EmployeeRequest::AGE_MIN || $age > EmployeeRequest::AGE_MAX) {
                $fail('birth_date', 'Umur pada tanggal masuk harus '.EmployeeRequest::AGE_MIN.'–'.EmployeeRequest::AGE_MAX.' tahun.');
            }
        }

        $number = $values['employee_number'] ?? null;
        if (is_string($number) && preg_match(self::NUMBER_PATTERN, $number) !== 1) {
            $fail('employee_number', 'No. pegawai maks. 30 karakter: huruf, angka, titik, garis miring, strip.');
            $number = null;
            $values['employee_number'] = null;
        }

        $address = $values['address'] ?? null;
        if (is_string($address) && mb_strlen($address) > 500) {
            $fail('address', 'Alamat maks. 500 karakter.');
        }

        $ids = [];
        foreach ([
            'employment_status_id' => ['employment_status', $this->statuses, 'Status kerja'],
            'location_id' => ['location', $this->locations, 'Lokasi'],
            'position_id' => ['position', $this->positions, 'Jabatan'],
        ] as $idKey => [$field, $index, $label]) {
            $text = $values[$field] ?? null;
            $ids[$idKey] = is_string($text) ? $this->reference($field, $text, $data, $fail, $index, $label) : null;
        }

        $supervisor = $values['supervisor_number'] ?? null;
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

        $errors = [];
        $ordered = [];
        foreach (self::ORDER as $field) {
            $messages = [...($row->errors[$field] ?? []), ...($extra[$field] ?? [])];
            if ($messages !== []) {
                $errors[$field] = $messages;
            }
            if (array_key_exists($field, $data)) {
                $ordered[$field] = $data[$field];
            }
        }
        $ordered['ids'] = array_filter($ids, fn ($id) => $id !== null);

        return new ValidatedRow($values, $ordered, $errors);
    }

    /** Kunci baris (RowKey) dari nilai yang sudah diperiksa. */
    public static function key(ValidatedRow $row): ?string
    {
        $string = fn (string $field) => is_string($row->values[$field] ?? null) ? $row->values[$field] : null;

        return RowKey::for($string('nik'), $string('employee_number'), $string('full_name'), $string('birth_date'));
    }

    /**
     * Normalisasi + pemeriksaan sekaligus (bentuk sebelum kontrak ImportType).
     *
     * @param  array<string, mixed>  $raw  field terpetakan => sel mentah
     * @return array{data: array<string, mixed>, errors: array<string, list<string>>, key: string|null}
     */
    public function validate(array $raw): array
    {
        $row = $this->check($this->normalize($raw));

        return ['data' => $row->data, 'errors' => $row->errors, 'key' => self::key($row)];
    }

    /**
     * Cocokkan kode atau nama (tidak peka huruf besar). Nama yang dipakai lebih dari satu data
     * dianggap ambigu: pengguna diminta memakai kode.
     *
     * @param  array<string, mixed>  $data
     * @param  array{code: array<string, list<int>>, name: array<string, list<int>>, label: array<int, string>}  $index
     */
    private function reference(string $field, string $text, array &$data, callable $fail, array $index, string $label): ?int
    {
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
