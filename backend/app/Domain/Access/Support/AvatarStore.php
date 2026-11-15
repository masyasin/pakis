<?php

namespace App\Domain\Access\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Avatar profil (FR-010, SEC-050, SEC-052). Pola sama dengan Settings/Branding/ImageProcessor:
 * isi berkas diperiksa (bukan ekstensi), lalu gambar di-encode ULANG lewat GD sehingga EXIF
 * (bisa memuat lokasi GPS) dan muatan selipan terbuang. Bedanya: disimpan di disk PRIVAT karena
 * foto orang adalah data pribadi; hanya bisa dibuka lewat route ber-auth.
 */
final class AvatarStore
{
    public const DISK = 'local';

    public const MAX_KB = 2048;

    private const SIZE = 256;

    private const ALLOWED = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP];

    /** Batas piksel sumber: mencegah "bom dekompresi" menghabiskan memori GD. */
    private const MAX_SOURCE_PIXELS = 40_000_000;

    /**
     * Dipakai juga untuk foto pegawai (T-024, folder `employee-photos`): aturan unggahnya sama.
     *
     * @return string path relatif di disk privat
     */
    public function store(UploadedFile $file, string $field = 'avatar', string $directory = 'avatars'): string
    {
        $fail = fn (string $message) => throw ValidationException::withMessages([$field => $message]);

        if ($file->getSize() > self::MAX_KB * 1024) {
            $fail('Ukuran foto maksimal 2 MB.');
        }

        $info = @getimagesize($file->getRealPath());
        if ($info === false || ! in_array($info[2], self::ALLOWED, true)) {
            $fail('Foto harus PNG, JPG, atau WebP. SVG tidak diterima.');
        }

        [$width, $height] = $info;
        if ($width * $height > self::MAX_SOURCE_PIXELS) {
            $fail('Resolusi foto terlalu besar.');
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $source instanceof GdImage) {
            $fail('Foto tidak bisa dibaca.');
        }

        // Potong persegi di tengah lalu perkecil: avatar selalu tampil bulat/persegi.
        $side = min($width, $height);
        $avatar = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($avatar, false);
        imagesavealpha($avatar, true);
        imagecopyresampled($avatar, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIZE, self::SIZE, $side, $side);

        ob_start();
        imagewebp($avatar, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($avatar);

        $path = $directory.'/'.Str::ulid().'.webp';
        Storage::disk(self::DISK)->put($path, $bytes);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** URL RELATIF (lolos CSP `img-src 'self'`), `v` mengganti cache browser saat avatar berganti. */
    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return route('admin.profile.avatar', ['v' => substr(hash('sha256', $path), 0, 12)], absolute: false);
    }
}
