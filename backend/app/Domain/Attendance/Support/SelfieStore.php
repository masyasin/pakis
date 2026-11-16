<?php

namespace App\Domain\Attendance\Support;

use App\Support\Upload\FileInspector;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Selfie absen (SEC-022). Hanya JPEG ASLI (diperiksa dari isi, bukan ekstensi/MIME kiriman):
 * tanda tangan FFD8FF, header SOF terbaca, dan bisa didekode GD. Metadata dibuang dengan
 * melewati segmen APP1 (EXIF/XMP, termasuk GPS), APP3–APP13/APP15 (IPTC, dll.), dan COM —
 * TANPA re-encode, sehingga piksel bukti tidak berubah. APP0 (JFIF), APP2 (profil warna ICC),
 * dan APP14 (Adobe, transformasi warna) dipertahankan karena dibutuhkan untuk tampil benar.
 * Disimpan di disk privat `local` dengan nama acak (ULID), tidak pernah lewat URL publik.
 */
final class SelfieStore
{
    public const DISK = 'local';

    /**
     * Batas teknis dimensi selfie (bukan parameter bisnis): Pakis Pro mengompres foto kamera ke
     * ±100 KB, jauh di bawah ini. Nilainya kini satu sumber di FileInspector (T-070).
     */
    public const MAX_SIDE_PX = FileInspector::MAX_SIDE_PX;

    public const MAX_PIXELS = FileInspector::MAX_PIXELS;

    /** JPEG sah & bisa didekode; dipakai validasi request agar galat tampil di `selfie`. */
    public static function isGenuineJpeg(string $bytes): bool
    {
        return FileInspector::isGenuineJpeg($bytes);
    }

    /** Buang segmen metadata (tanpa re-encode). Null bila struktur JPEG rusak. */
    public static function strip(string $bytes): ?string
    {
        return FileInspector::stripJpeg($bytes);
    }

    /** Simpan selfie bersih; path relatif disk privat, mis. `selfies/2026/11/01jc….jpg`. */
    public function store(UploadedFile $file, CarbonInterface $localDate): string
    {
        $clean = self::strip((string) $file->get());
        if ($clean === null) {
            // Sudah divalidasi request; sampai sini berarti berkas berubah di tengah jalan.
            throw new \RuntimeException('Selfie bukan JPEG yang sah.');
        }

        $path = sprintf('selfies/%s/%s.jpg', $localDate->format('Y/m'), strtolower((string) Str::ulid()));
        Storage::disk(self::DISK)->put($path, $clean);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
