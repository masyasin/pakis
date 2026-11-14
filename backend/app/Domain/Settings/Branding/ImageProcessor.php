<?php

namespace App\Domain\Settings\Branding;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Unggahan logo & favicon (BR-021, SEC-052).
 *
 * Berkas TIDAK disimpan apa adanya: gambar dibaca GD lalu di-encode ulang. Ini membuang metadata
 * (EXIF bisa memuat lokasi GPS kamera) dan menetralkan berkas "gambar" yang diselipi muatan lain.
 * SVG ditolak karena bisa memuat script. Nama berkas acak di disk `public`.
 */
final class ImageProcessor
{
    public const DISK = 'public';

    private const MAX_BYTES = 1024 * 1024;

    private const ALLOWED = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];

    /**
     * @return string path relatif di disk public
     */
    public function store(UploadedFile $file, string $field, bool $favicon = false): string
    {
        $fail = fn (string $message) => throw ValidationException::withMessages([$field => $message]);

        if ($file->getSize() > self::MAX_BYTES) {
            $fail('Ukuran gambar maksimal 1 MB.');
        }

        $info = @getimagesize($file->getRealPath());
        $type = $info[2] ?? null;
        if ($info === false || ! isset(self::ALLOWED[$type])) {
            $fail('Hanya PNG, JPG, atau WebP. SVG tidak diterima.');
        }

        [$width, $height] = $info;

        if ($favicon) {
            if ($type !== IMAGETYPE_PNG || $width !== $height || $width < 32 || $width > 512) {
                $fail('Favicon harus PNG persegi berukuran 32–512 px.');
            }
        } elseif ($width > 1024 || $height > 1024) {
            $fail('Ukuran gambar maksimal 1024 × 1024 px.');
        }

        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $image instanceof GdImage) {
            $fail('Gambar tidak bisa dibaca.');
        }

        $extension = self::ALLOWED[$type];
        $path = 'branding/'.Str::ulid().'.'.$extension;

        Storage::disk(self::DISK)->put($path, self::encode($image, $extension));
        imagedestroy($image);

        return $path;
    }

    private static function encode(GdImage $image, string $extension): string
    {
        // Pertahankan transparansi PNG/WebP.
        imagesavealpha($image, true);

        ob_start();
        match ($extension) {
            'png' => imagepng($image, null, 9),
            'webp' => imagewebp($image, null, 90),
            default => imagejpeg($image, null, 90),
        };

        return (string) ob_get_clean();
    }
}
