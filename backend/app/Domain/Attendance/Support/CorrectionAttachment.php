<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Support\Upload\FileInspector;
use App\Support\Upload\FileKind;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lampiran koreksi (05 §5.7, BR-069): JPEG/PNG/PDF ≤ `attendance.correction_attachment_max_kb`.
 * Jenis ditentukan dari ISI berkas, bukan ekstensi/MIME kiriman klien (teks bernama .jpg ditolak).
 * Metadata foto dibuang (EXIF bisa memuat koordinat & model HP). Disimpan di disk privat yang sama
 * dengan selfie; hanya bisa diunduh lewat route ber-izin (T-041).
 */
final class CorrectionAttachment
{
    /** @return 'jpg'|'png'|'pdf'|null jenis sah menurut isi, null = ditolak (FileInspector, T-070) */
    public static function kind(string $bytes): ?string
    {
        $kind = FileInspector::kind($bytes, [FileKind::Jpg, FileKind::Png, FileKind::Pdf]);

        return match ($kind) {
            FileKind::Jpg => 'jpg',
            FileKind::Png => 'png',
            FileKind::Pdf => 'pdf',
            default => null,
        };
    }

    public static function maxKb(): int
    {
        return (int) setting('attendance.correction_attachment_max_kb');
    }

    /** Simpan versi bersih; path relatif disk privat, mis. `corrections/2026/11/01jc….png`. */
    public function store(UploadedFile $file, CarbonInterface $localDate): string
    {
        $bytes = (string) $file->get();
        $kind = self::kind($bytes);
        $clean = match ($kind) {
            'jpg' => FileInspector::stripJpeg($bytes),
            'png' => FileInspector::stripPng($bytes),
            'pdf' => $bytes,
            default => null,
        };
        if ($kind === null || $clean === null) {
            // Sudah divalidasi request; sampai sini berarti berkas berubah di tengah jalan.
            throw new RuntimeException('Lampiran koreksi bukan JPEG/PNG/PDF yang sah.');
        }

        $path = sprintf('corrections/%s/%s.%s', $localDate->format('Y/m'), strtolower((string) Str::ulid()), $kind);
        Storage::disk(SelfieStore::DISK)->put($path, $clean);

        return $path;
    }

    /**
     * Unduhan lampiran untuk web & API (T-041, T-043): selalu `attachment` + `no-store` + nosniff,
     * jenis dari ekstensi yang KITA tetapkan saat menyimpan (bukan kiriman klien). Pemanggil sudah
     * memeriksa wewenang; tanpa lampiran / berkas hilang → 404.
     */
    public static function download(AttendanceCorrection $correction): StreamedResponse
    {
        $path = $correction->attachment_path;
        abort_unless($path !== null && Storage::disk(SelfieStore::DISK)->exists($path), 404);

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return Storage::disk(SelfieStore::DISK)->download($path, "lampiran-{$correction->number}.{$extension}", [
            'Content-Type' => match ($extension) {
                'pdf' => 'application/pdf',
                'png' => 'image/png',
                default => 'image/jpeg',
            },
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function delete(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(SelfieStore::DISK)->delete($path);
        }
    }
}
