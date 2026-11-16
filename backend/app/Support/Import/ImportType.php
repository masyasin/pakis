<?php

namespace App\Support\Import;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Kontrak satu tipe impor (BR-170, T-085A). Kerangka (`app/Support/Import`) mengurus unggah, baris
 * judul, pemetaan kolom + profil, staging `import_rows`, duplikat dalam berkas, antrean, proses per
 * baris dalam transaksi, berkas galat, audit, dan notifikasi. Tipe hanya menjelaskan field-nya dan
 * cara menormalkan, memvalidasi, mengunci, serta menulis satu baris.
 *
 * Daftarkan di `config('pakis.imports')` (kunci = `key()`).
 */
interface ImportType
{
    /** Nilai `import_batches.type` / `import_mapping_profiles.type`. */
    public function key(): string;

    /** Nama data untuk judul & pesan, mis. "Pegawai". */
    public function label(): string;

    /**
     * Segmen rute `/impor/{slug}` (`admin.master-imports.*`). `null` = tipe punya rute sendiri
     * (impor pegawai memakai `impor/pegawai…` sejak Fase 0).
     */
    public function routeSlug(): ?string;

    /** Izin untuk mengunggah, melihat, dan memproses batch tipe ini. */
    public function permission(): string;

    /** Izin tambahan untuk opsi "perbarui data yang sudah ada"; `null` = opsi tidak tersedia. */
    public function updatePermission(): ?string;

    public function fields(): ImportFields;

    /**
     * Data sekali per lintasan validasi/proses (indeks referensi ber-scope, nilai lain di berkas yang
     * sama, dsb.). Diteruskan apa adanya ke method lain.
     */
    public function context(User $actor, ImportBatch $batch, ImportPhase $phase): object;

    /**
     * Normalkan sel mentah per field (teks, tanggal, angka). Nilai yang gagal dicatat galatnya per
     * field; tidak ada akses data lain di sini.
     *
     * @param  array<string, mixed>  $raw  field terpetakan => sel mentah
     */
    public function normalize(array $raw, object $context): NormalizedRow;

    /** Aturan lintas field & data lain (referensi, batas, keunikan) atas hasil `normalize()`. */
    public function validate(NormalizedRow $row, object $context): ValidatedRow;

    /**
     * Kunci gabungan mentah baris ini (mis. "nik:…" atau "lokasi|sku|batch|bin"), dipakai untuk
     * duplikat dalam berkas. Yang disimpan di `import_rows.row_key` adalah sha256-nya. `null` = baris
     * tidak bisa dikunci.
     */
    public function rowKey(ValidatedRow $row): ?string;

    /** Penjelasan kunci untuk pesan duplikat, mis. "NIK sama". */
    public function describeKey(string $key): string;

    /**
     * Urutan proses baris valid (mis. atasan sebelum bawahan) dan baris yang tidak boleh diproses
     * beserta galatnya.
     *
     * @param  array<int, array<string, mixed>>  $raws  nomor baris → sel mentah terpetakan
     */
    public function processOrder(array $raws): ImportOrder;

    /**
     * Tulis satu baris valid ke data domain. Dipanggil di dalam transaksi baris; lempar
     * ValidationException agar baris menjadi `invalid` tanpa menghentikan batch.
     *
     * @param  array<string, mixed>  $raw
     *
     * @throws ValidationException
     */
    public function apply(User $actor, ValidatedRow $row, array $raw, bool $updateExisting, object $context): AppliedRow;

    /**
     * Dipanggil setelah transaksi baris berhasil di-commit (mis. mencatat id pegawai baru agar
     * bawahannya di berkas yang sama bisa merujuknya).
     *
     * @param  array<string, mixed>  $raw
     */
    public function committed(object $context, array $raw, AppliedRow $row): void;

    /** Kunci galat dari Action domain (mis. `assignment.position_id`) → nama field impor. */
    public function errorField(string $key): string;
}
