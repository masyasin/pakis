<?php

namespace App\Domain\Attendance\Support;

use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Lampiran koreksi (05 §5.7, BR-069): JPEG/PNG/PDF ≤ `attendance.correction_attachment_max_kb`.
 * Jenis ditentukan dari ISI berkas, bukan ekstensi/MIME kiriman klien (teks bernama .jpg ditolak).
 * Metadata foto dibuang (EXIF bisa memuat koordinat & model HP). Disimpan di disk privat yang sama
 * dengan selfie; hanya bisa diunduh lewat route ber-izin (T-041).
 */
final class CorrectionAttachment
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** Chunk PNG metadata yang dibuang: teks bebas, EXIF, waktu ubah. */
    private const PNG_DROP = ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME'];

    /** @return 'jpg'|'png'|'pdf'|null jenis sah menurut isi, null = ditolak */
    public static function kind(string $bytes): ?string
    {
        if (SelfieStore::isGenuineJpeg($bytes)) {
            return 'jpg';
        }
        if (self::isGenuinePng($bytes)) {
            return 'png';
        }
        if (self::isPdf($bytes)) {
            return 'pdf';
        }

        return null;
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
            'jpg' => SelfieStore::strip($bytes),
            'png' => self::stripPng($bytes),
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

    public function delete(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(SelfieStore::DISK)->delete($path);
        }
    }

    private static function isGenuinePng(string $bytes): bool
    {
        if (! str_starts_with($bytes, self::PNG_SIGNATURE)) {
            return false;
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== IMAGETYPE_PNG) {
            return false;
        }

        // Dimensi dicek sebelum dekode (bom dekompresi), batas sama dengan selfie.
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width > SelfieStore::MAX_SIDE_PX || $height > SelfieStore::MAX_SIDE_PX || $width * $height > SelfieStore::MAX_PIXELS) {
            return false;
        }

        if (self::stripPng($bytes) === null) {
            return false;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return false;
        }
        imagedestroy($image);

        return true;
    }

    /** Buang chunk metadata; null bila struktur rusak (panjang chunk melewati akhir, tanpa IEND). */
    private static function stripPng(string $bytes): ?string
    {
        $out = self::PNG_SIGNATURE;
        $pos = strlen(self::PNG_SIGNATURE);
        $total = strlen($bytes);

        while ($pos + 12 <= $total) {
            $unpacked = unpack('N', substr($bytes, $pos, 4));
            $length = is_array($unpacked) ? (int) $unpacked[1] : -1;
            if ($length < 0 || $pos + 12 + $length > $total) {
                return null;
            }

            $type = substr($bytes, $pos + 4, 4);
            $chunk = substr($bytes, $pos, 12 + $length);
            if (! in_array($type, self::PNG_DROP, true)) {
                $out .= $chunk;
            }
            $pos += 12 + $length;

            if ($type === 'IEND') {
                return $out;
            }
        }

        return null;
    }

    /** PDF: header `%PDF-1.x`/`2.0` di awal dan penanda `%%EOF` di akhir berkas. */
    private static function isPdf(string $bytes): bool
    {
        if (preg_match('/^%PDF-[12]\.\d/', $bytes) !== 1) {
            return false;
        }

        return str_contains(substr($bytes, -1024), '%%EOF');
    }
}
