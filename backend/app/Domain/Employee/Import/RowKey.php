<?php

namespace App\Domain\Employee\Import;

use App\Domain\Employee\Support\NikCipher;

/**
 * Kunci baris impor (BR-054): `nik_hash` bila NIK ada, lalu `employee_number`, lalu hash(nama
 * huruf kecil + tanggal lahir). Diberi awalan jenis agar ketiganya tidak pernah bertabrakan.
 * Dipakai untuk mendeteksi duplikat dalam berkas (T-027) dan mencocokkan data yang sudah ada (T-028).
 */
final class RowKey
{
    public static function for(?string $nik, ?string $employeeNumber, ?string $fullName, ?string $birthDate): ?string
    {
        return match (true) {
            $nik !== null => 'nik:'.app(NikCipher::class)->hash($nik),
            $employeeNumber !== null => 'no:'.mb_strtolower($employeeNumber),
            $fullName !== null => 'nm:'.hash('sha256', mb_strtolower($fullName).'|'.($birthDate ?? '')),
            default => null,
        };
    }

    /** Penjelasan jenis kunci untuk pesan duplikat. */
    public static function describe(string $key): string
    {
        return match (substr($key, 0, 3)) {
            'nik' => 'NIK sama',
            'no:' => 'No. pegawai sama',
            default => 'nama dan tanggal lahir sama',
        };
    }
}
