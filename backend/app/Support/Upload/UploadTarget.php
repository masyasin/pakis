<?php

namespace App\Support\Upload;

/**
 * Aturan satu tujuan unggahan (SEC-109): jenis yang diterima, disk, folder, dan ukuran sisi maks
 * gambar setelah diproses. Dokumen & foto bukti selalu di disk PRIVAT; disk publik hanya untuk
 * gambar yang memang tampil publik dan bukan data pribadi (foto produk, T-070).
 */
final class UploadTarget
{
    /**
     * @param  list<FileKind>  $allowed
     */
    private function __construct(
        public readonly array $allowed,
        public readonly string $disk,
        public readonly string $directory,
        public readonly ?int $maxSidePx,
        public readonly string $field,
    ) {}

    /** Dokumen/foto bukti (izin edar, berita acara, foto opname, …): JPG/PNG/WEBP/PDF, disk privat. */
    public static function privateDocument(string $directory, string $field = 'file'): self
    {
        return new self([...FileKind::images(), FileKind::Pdf], 'local', $directory, null, $field);
    }

    /** Foto bukti saja (tanpa PDF), disk privat, diperkecil agar hemat ruang. */
    public static function privatePhoto(string $directory, string $field = 'photo', int $maxSidePx = 1600): self
    {
        return new self(FileKind::images(), 'local', $directory, $maxSidePx, $field);
    }

    /** Foto produk: disk publik, diperkecil ke thumbnail (T-070). Bukan data pribadi. */
    public static function productPhoto(int $maxSidePx = 400): self
    {
        return new self(FileKind::images(), 'public', 'products', $maxSidePx, 'photo');
    }
}
