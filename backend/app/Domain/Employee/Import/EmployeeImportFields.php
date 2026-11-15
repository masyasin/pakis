<?php

namespace App\Domain\Employee\Import;

/**
 * Field sistem untuk impor pegawai (BR-054) beserta sinonim judul kolom yang dikenali. Sinonim
 * ditulis dalam bentuk ternormalisasi (`normalizeHeader`): huruf kecil, isi kurung dibuang,
 * tanda baca → spasi. Satu sumber untuk saran pemetaan, deteksi baris header, dan templat.
 */
final class EmployeeImportFields
{
    /**
     * @var array<string, array{label: string, required: bool, synonyms: list<string>, hint: string}>
     */
    public const FIELDS = [
        'full_name' => [
            'label' => 'Nama Lengkap', 'required' => true, 'hint' => 'Sesuai KTP',
            'synonyms' => ['nama', 'nama lengkap', 'nama pegawai', 'nama karyawan', 'nama sesuai ktp', 'name', 'full name'],
        ],
        'gender' => [
            'label' => 'Jenis Kelamin', 'required' => true, 'hint' => 'L/P, Laki-laki/Perempuan, Pria/Wanita',
            'synonyms' => ['jenis kelamin', 'kelamin', 'jk', 'l p', 'gender', 'sex'],
        ],
        'join_date' => [
            'label' => 'Tanggal Masuk', 'required' => true, 'hint' => '31/12/2024, 2024-12-31, atau 31 Des 2024',
            'synonyms' => ['tanggal masuk', 'tgl masuk', 'tanggal bergabung', 'tgl bergabung', 'mulai kerja', 'tanggal mulai kerja', 'tmt', 'tmt kerja', 'masuk kerja', 'join date'],
        ],
        'employment_status' => [
            'label' => 'Status Kerja', 'required' => true, 'hint' => 'Kode atau nama di Referensi, mis. Tetap',
            'synonyms' => ['status kerja', 'status', 'status karyawan', 'status pegawai', 'status kepegawaian', 'status hubungan kerja', 'employment status'],
        ],
        'location' => [
            'label' => 'Lokasi', 'required' => true, 'hint' => 'Kode atau nama lokasi',
            'synonyms' => ['lokasi', 'lokasi kerja', 'lokasi tugas', 'kode lokasi', 'penempatan', 'cabang', 'unit kerja', 'gudang toko', 'location'],
        ],
        'position' => [
            'label' => 'Jabatan', 'required' => true, 'hint' => 'Kode atau nama jabatan',
            'synonyms' => ['jabatan', 'kode jabatan', 'posisi', 'position', 'job title'],
        ],
        'employee_number' => [
            'label' => 'No. Pegawai', 'required' => false, 'hint' => 'Kosongkan agar dibuat otomatis',
            'synonyms' => ['no pegawai', 'nomor pegawai', 'no karyawan', 'nomor karyawan', 'kode pegawai', 'id pegawai', 'id karyawan', 'nip', 'no induk', 'nomor induk pegawai', 'employee number'],
        ],
        'nik' => [
            'label' => 'NIK', 'required' => false, 'hint' => '16 digit, ketik sebagai teks',
            'synonyms' => ['nik', 'nik ktp', 'no ktp', 'nomor ktp', 'ktp', 'no nik', 'nomor nik', 'nomor induk kependudukan'],
        ],
        'birth_date' => [
            'label' => 'Tanggal Lahir', 'required' => false, 'hint' => 'Format sama dengan tanggal masuk',
            'synonyms' => ['tanggal lahir', 'tgl lahir', 'tgl lhr', 'lahir', 'birth date', 'date of birth'],
        ],
        'phone' => [
            'label' => 'No. HP', 'required' => false, 'hint' => '0812-3456-7890 atau 62812…',
            'synonyms' => ['no hp', 'nomor hp', 'hp', 'no wa', 'nomor wa', 'wa', 'whatsapp', 'no whatsapp', 'telepon', 'telp', 'no telp', 'no telepon', 'nomor telepon', 'handphone', 'phone', 'ponsel'],
        ],
        'email' => [
            'label' => 'Email', 'required' => false, 'hint' => '',
            'synonyms' => ['email', 'e mail', 'alamat email', 'surel'],
        ],
        'address' => [
            'label' => 'Alamat', 'required' => false, 'hint' => '',
            'synonyms' => ['alamat', 'alamat lengkap', 'alamat rumah', 'alamat ktp', 'domisili', 'address'],
        ],
        'supervisor_number' => [
            'label' => 'No. Pegawai Atasan', 'required' => false, 'hint' => 'Atasan harus pegawai aktif',
            'synonyms' => ['no pegawai atasan', 'nomor pegawai atasan', 'no atasan', 'nip atasan', 'atasan', 'atasan langsung', 'supervisor'],
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::FIELDS);
    }

    /** @return list<string> */
    public static function required(): array
    {
        return array_keys(array_filter(self::FIELDS, fn (array $f) => $f['required']));
    }

    public static function label(string $field): string
    {
        return self::FIELDS[$field]['label'] ?? $field;
    }

    /**
     * "No. HP (WA)*" → "no hp"; "NAMA  PEGAWAI" → "nama pegawai". Kurung dibuang karena biasanya
     * berisi petunjuk format, bukan nama kolom.
     */
    public static function normalizeHeader(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        $text = mb_strtolower(trim((string) $value));
        $text = (string) preg_replace('/\([^)]*\)|\[[^]]*\]/u', ' ', $text);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim($text);
    }

    /** Field untuk judul kolom ini menurut sinonim, atau null. */
    public static function fieldForHeader(mixed $header): ?string
    {
        $normalized = self::normalizeHeader($header);
        if ($normalized === '') {
            return null;
        }

        foreach (self::FIELDS as $key => $field) {
            if (in_array($normalized, $field['synonyms'], true)) {
                return $key;
            }
        }

        return null;
    }
}
