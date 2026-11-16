<?php

namespace App\Domain\Employee\Import;

/**
 * Konteks satu lintasan impor pegawai: validator (indeks referensi ber-scope + nomor pegawai di
 * berkas) dan, saat proses, id pegawai yang dibuat dari baris sebelumnya di berkas yang sama.
 */
final class EmployeeImportContext
{
    /** @var array<string, int> nomor pegawai (huruf kecil) → id */
    public array $resolved = [];

    public function __construct(public readonly RowValidator $validator) {}
}
