<?php

namespace App\Support\Upload;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Satu komponen unggahan untuk semua berkas baru Paket A (SEC-109, PA-22; dipakai T-070, T-071,
 * T-074, T-080, T-083, T-084). Diekstrak dari kontrol Fase 0 (SEC-050, SEC-052):
 * - jenis ditentukan dari ISI berkas (FileInspector), bukan ekstensi/MIME klien;
 * - batas ukuran dari setting `upload.max_image_kb` (gambar) / `upload.max_pdf_kb` (PDF);
 * - gambar di-encode ULANG lewat GD: EXIF (termasuk lokasi GPS) dan muatan selipan terbuang, dan
 *   bisa diperkecil; PDF disimpan apa adanya setelah lolos pemeriksaan;
 * - nama acak (ULID), disk & folder dari UploadTarget.
 */
final class SecureUpload
{
    public function store(UploadedFile $file, UploadTarget $target): StoredUpload
    {
        $fail = fn (string $message) => throw ValidationException::withMessages([$target->field => $message]);

        $maxImageKb = (int) setting('upload.max_image_kb');
        $maxPdfKb = (int) setting('upload.max_pdf_kb');
        // Tolak sebelum membaca isi: berkas jauh di atas batas apa pun tidak perlu dimuat ke memori.
        if ((int) $file->getSize() > max($maxImageKb, $maxPdfKb) * 1024) {
            $fail('Ukuran berkas melebihi batas.');
        }

        $bytes = (string) $file->get();
        $kind = FileInspector::kind($bytes, $target->allowed);
        if ($kind === null) {
            $labels = implode(', ', array_map(fn (FileKind $k) => $k->label(), $target->allowed));
            $fail("Berkas harus {$labels} yang asli. Isi berkas tidak cocok dengan jenisnya.");
        }

        $limitKb = $kind->isImage() ? $maxImageKb : $maxPdfKb;
        if (strlen($bytes) > $limitKb * 1024) {
            $fail(sprintf('Ukuran %s maksimal %s.', $kind->label(), self::sizeLabel($limitKb)));
        }

        $clean = $kind->isImage() ? self::reencode($bytes, $kind, $target->maxSidePx) : $bytes;
        if ($clean === null) {
            $fail('Gambar tidak bisa diproses. Coba simpan ulang gambarnya lalu unggah lagi.');
        }

        $path = sprintf('%s/%s/%s.%s', $target->directory, now()->format('Y/m'), strtolower((string) Str::ulid()), $kind->value);
        Storage::disk($target->disk)->put($path, $clean);

        return new StoredUpload($target->disk, $path, $kind, strlen($clean));
    }

    public function delete(string $disk, ?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk($disk)->delete($path);
        }
    }

    /**
     * Encode ulang gambar lewat GD (membuang semua metadata), opsional diperkecil agar sisi
     * terpanjang ≤ `$maxSide` dengan rasio tetap. Jenis keluaran = jenis masukan.
     */
    private static function reencode(string $bytes, FileKind $kind, ?int $maxSide): ?string
    {
        $source = @imagecreatefromstring($bytes);
        if (! $source instanceof GdImage) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $image = $source;
        if ($maxSide !== null && max($width, $height) > $maxSide) {
            $scale = $maxSide / max($width, $height);
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));
            $image = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagecopyresampled($image, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($source);
        } else {
            imagesavealpha($image, true);
        }

        ob_start();
        $ok = match ($kind) {
            FileKind::Jpg => imagejpeg($image, null, 85),
            FileKind::Png => imagepng($image, null, 6),
            FileKind::Webp => imagewebp($image, null, 85),
            FileKind::Pdf => false,
        };
        $out = (string) ob_get_clean();
        imagedestroy($image);

        return $ok && $out !== '' ? $out : null;
    }

    private static function sizeLabel(int $kb): string
    {
        return $kb >= 1024 && $kb % 1024 === 0 ? ($kb / 1024).' MB' : "{$kb} KB";
    }
}
