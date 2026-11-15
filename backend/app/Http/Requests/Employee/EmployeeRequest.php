<?php

namespace App\Http\Requests\Employee;

use App\Domain\Access\DataScope;
use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Support\AvatarStore;
use App\Domain\Access\Support\ScopeResolver;
use App\Domain\Employee\Enums\Gender;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\PersonName;
use App\Domain\Employee\Support\PhoneNormalizer;
use App\Domain\Organization\Enums\AttendanceMode;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Support\LocationScope;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tambah / ubah pegawai (FR-025, BR-050). Saat tambah, form gabungan juga memuat penugasan
 * pertama (`assignment.*`) dan opsi buat akun (`account.*`).
 *
 * SEC-034: `employee_number`, `status`, `nik_hash`, `created_by` tidak punya aturan, jadi tidak
 * pernah ikut `validated()` walau dikirim.
 */
class EmployeeRequest extends FormRequest
{
    /** BR-050: umur pada tanggal masuk. Aturan bisnis tetap (bukan setting), sama seperti NIK 16 digit. */
    public const AGE_MIN = 15;

    public const AGE_MAX = 80;

    public function authorize(): bool
    {
        return true; // middleware `can:employees.*` + EmployeePolicy di controller
    }

    private function creating(): bool
    {
        return ! $this->route('employee') instanceof Employee;
    }

    protected function prepareForValidation(): void
    {
        $text = fn (mixed $value) => is_string($value) && trim($value) !== '' ? trim($value) : null;
        $nik = $text($this->input('nik'));

        $merge = [
            'full_name' => is_string($this->input('full_name')) ? PersonName::clean($this->input('full_name')) : $this->input('full_name'),
            // Spasi di NIK hanya pemisah tampilan (fotokopi KTP sering ditulis berkelompok).
            'nik' => $nik !== null ? (string) preg_replace('/\s+/', '', $nik) : null,
            'birth_date' => $text($this->input('birth_date')),
            'phone' => $text($this->input('phone')),
            'email' => ($email = $text($this->input('email'))) !== null ? mb_strtolower($email) : null,
            'address' => $text($this->input('address')),
            'village_code' => $text($this->input('village_code')),
            'remove_nik' => $this->boolean('remove_nik'),
            'remove_photo' => $this->boolean('remove_photo'),
        ];

        if ($this->creating()) {
            $assignment = is_array($this->input('assignment')) ? $this->input('assignment') : [];
            $merge['assignment'] = [
                ...$assignment,
                'supervisor_employee_id' => $text((string) ($assignment['supervisor_employee_id'] ?? '')),
                'attendance_mode' => $text((string) ($assignment['attendance_mode'] ?? '')),
            ];

            $account = is_array($this->input('account')) ? $this->input('account') : [];
            $merge['account'] = [
                ...$account,
                'create' => filter_var($account['create'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'username' => is_string($account['username'] ?? null) ? mb_strtolower(trim($account['username'])) : null,
                'roles' => is_array($account['roles'] ?? null) ? array_values(array_unique(array_filter($account['roles'], 'is_string'))) : [],
            ];
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $employee = $this->route('employee');
        $currentStatus = $employee instanceof Employee ? $employee->employment_status_id : null;

        $rules = [
            'full_name' => ['required', 'string', 'min:2', 'max:150'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'nik' => ['nullable', 'string'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'village_code' => ['nullable', 'string', 'regex:/^\d{2}\.\d{2}\.\d{2}\.\d{4}$/', Rule::exists('villages', 'code')],
            'employment_status_id' => [
                'required', 'integer',
                // Status kerja nonaktif tidak bisa dipilih untuk data baru; yang sudah terpasang boleh dipertahankan.
                Rule::exists('reference_items', 'id')->where(fn (Builder $q) => $q
                    ->where('group_code', ReferenceGroup::EmploymentStatus->value)
                    ->where(fn (Builder $w) => $w->where('is_active', true)->when($currentStatus !== null, fn (Builder $o) => $o->orWhere('id', $currentStatus)))),
            ],
            'join_date' => ['required', 'date_format:Y-m-d'],
            // Isi berkas diperiksa lagi oleh AvatarStore (getimagesize + encode ulang, SEC-050).
            'photo' => ['nullable', 'file', 'max:'.AvatarStore::MAX_KB, 'mimes:png,jpg,jpeg,webp'],
            'remove_nik' => ['boolean'],
            'remove_photo' => ['boolean'],
        ];

        if (! $this->creating()) {
            return $rules;
        }

        return [
            ...$rules,
            'assignment' => ['required', 'array'],
            'assignment.position_id' => ['required', 'integer', Rule::exists('positions', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'assignment.location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'assignment.supervisor_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'assignment.attendance_mode' => ['nullable', Rule::enum(AttendanceMode::class)],
            'account' => ['array'],
            'account.create' => ['boolean'],
            'account.username' => ['exclude_unless:account.create,true', 'required', 'string', 'regex:/^[a-z0-9._-]{3,50}$/', Rule::unique(User::class, 'username')],
            'account.roles' => ['exclude_unless:account.create,true', 'array', 'max:20'],
            'account.roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'account.password_mode' => ['exclude_unless:account.create,true', 'required', Rule::in(['generate', 'email_link'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $nik = $this->input('nik');
            if (is_string($nik) && preg_match('/^\d{16}$/', $nik) !== 1) {
                $v->errors()->add('nik', preg_match('/^\d+$/', $nik) === 1
                    ? 'NIK harus 16 digit angka (sekarang '.strlen($nik).').'
                    : 'NIK hanya boleh berisi angka, tepat 16 digit.');
            }

            $phone = $this->input('phone');
            if (is_string($phone) && PhoneNormalizer::normalize($phone) === null) {
                $v->errors()->add('phone', 'Nomor HP tidak valid. Gunakan nomor Indonesia 10–15 digit, contoh 0812-3456-7890.');
            }

            $this->checkAge($v);

            if ($this->creating()) {
                $this->checkAssignmentScope($v);

                $actor = $this->user();
                if ($this->input('account.create') === true && (! $actor instanceof User || ! $actor->can('users.create') || ScopeResolver::for($actor) !== Scope::All)) {
                    // Sama dengan EmployeePolicy::createAccount: menautkan akun = menentukan scope akun.
                    $v->errors()->add('account.username', 'Anda tidak punya izin membuat akun. Hilangkan centang "Buat akun".');
                }
            }
        });
    }

    private function checkAge(Validator $v): void
    {
        $birth = $this->input('birth_date');
        $join = $this->input('join_date');
        if (! is_string($birth) || ! is_string($join) || $v->errors()->hasAny(['birth_date', 'join_date'])) {
            return;
        }

        $birthDate = CarbonImmutable::createFromFormat('Y-m-d', $birth);
        $joinDate = CarbonImmutable::createFromFormat('Y-m-d', $join);
        if ($birthDate === null || $joinDate === null) {
            return;
        }

        // Umur penuh (tahun yang sudah dilewati), seperti umur di KTP.
        $age = (int) floor($birthDate->diffInYears($joinDate, absolute: false));
        if ($age < self::AGE_MIN || $age > self::AGE_MAX) {
            $v->errors()->add('birth_date', 'Umur pada tanggal masuk harus '.self::AGE_MIN.'–'.self::AGE_MAX.' tahun. Periksa tanggal lahir dan tanggal masuk.');
        }
    }

    /**
     * Lokasi & atasan penugasan harus dalam scope pembuat. Di luar scope diperlakukan "tidak
     * ditemukan" agar keberadaan data di luar scope tidak bocor (SEC-033).
     */
    private function checkAssignmentScope(Validator $v): void
    {
        $user = $this->user();
        if (! $user instanceof User) {
            return;
        }

        $locationId = $this->input('assignment.location_id');
        $location = is_numeric($locationId) ? Location::query()->find((int) $locationId) : null;
        if ($location !== null && ! app(LocationScope::class)->allows($user, $location)) {
            $v->errors()->add('assignment.location_id', 'Lokasi tidak ditemukan.');
        }

        $supervisorId = $this->input('assignment.supervisor_employee_id');
        if (is_numeric($supervisorId) && ! app(DataScope::class)->allows($user, (int) $supervisorId)) {
            $v->errors()->add('assignment.supervisor_employee_id', 'Atasan tidak ditemukan.');
        }
    }

    /**
     * Data siap simpan: nama rapi, HP `62…`, ID sebagai int. Kunci di luar aturan tidak ikut.
     *
     * @return array<string, mixed>
     */
    public function employeeData(): array
    {
        $data = $this->validated();
        unset($data['photo']);

        $data['phone'] = PhoneNormalizer::normalize($data['phone'] ?? null);
        $data['employment_status_id'] = (int) $data['employment_status_id'];

        if (isset($data['assignment'])) {
            $data['assignment'] = [
                'position_id' => (int) $data['assignment']['position_id'],
                'location_id' => (int) $data['assignment']['location_id'],
                'supervisor_employee_id' => isset($data['assignment']['supervisor_employee_id']) ? (int) $data['assignment']['supervisor_employee_id'] : null,
                'attendance_mode' => $data['assignment']['attendance_mode'] ?? null,
            ];
        }

        $account = $data['account'] ?? [];
        unset($data['account']);
        if (($account['create'] ?? false) === true) {
            $data['account'] = [
                'username' => (string) $account['username'],
                'roles' => array_values($account['roles'] ?? []),
                'password_mode' => (string) $account['password_mode'],
            ];
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'full_name.required' => 'Nama pegawai wajib diisi.',
            'gender.required' => 'Pilih jenis kelamin.',
            'birth_date.before' => 'Tanggal lahir harus sebelum hari ini.',
            'birth_date.date_format' => 'Tanggal lahir tidak valid.',
            'join_date.required' => 'Tanggal masuk wajib diisi.',
            'join_date.date_format' => 'Tanggal masuk tidak valid.',
            'employment_status_id.required' => 'Pilih status kerja.',
            'employment_status_id.exists' => 'Pilih status kerja yang aktif.',
            'village_code.regex' => 'Pilih desa/kelurahan dari daftar.',
            'village_code.exists' => 'Desa/kelurahan tidak ditemukan.',
            'photo.max' => 'Ukuran foto maksimal 2 MB.',
            'photo.mimes' => 'Foto harus PNG, JPG, atau WebP. SVG tidak diterima.',
            'assignment.position_id.required' => 'Pilih jabatan.',
            'assignment.position_id.exists' => 'Jabatan tidak ditemukan atau sudah nonaktif.',
            'assignment.location_id.required' => 'Pilih lokasi tugas.',
            'assignment.location_id.exists' => 'Lokasi tidak ditemukan atau sudah nonaktif.',
            'assignment.supervisor_employee_id.exists' => 'Atasan harus pegawai yang masih aktif.',
            'account.username.required' => 'Isi username akun.',
            'account.username.regex' => 'Username 3–50 karakter: huruf kecil, angka, titik, garis bawah, atau tanda hubung.',
            'account.username.unique' => 'Username ini sudah dipakai akun lain.',
            'account.roles.*.exists' => 'Role yang dipilih tidak ditemukan.',
            'account.password_mode.in' => 'Pilih cara membuat password awal.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'full_name' => 'nama', 'gender' => 'jenis kelamin', 'nik' => 'NIK', 'birth_date' => 'tanggal lahir',
            'phone' => 'HP', 'email' => 'email', 'address' => 'alamat', 'village_code' => 'desa/kelurahan',
            'employment_status_id' => 'status kerja', 'join_date' => 'tanggal masuk', 'photo' => 'foto',
            'assignment.position_id' => 'jabatan', 'assignment.location_id' => 'lokasi',
            'assignment.supervisor_employee_id' => 'atasan', 'assignment.attendance_mode' => 'mode absen',
            'account.username' => 'username', 'account.password_mode' => 'password awal',
        ];
    }
}
