<?php

use App\Domain\Settings\Branding\ImageProcessor;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/*
 * BR-021 / SEC-052: logo & favicon hanya PNG/JPG/WebP, metadata dibuang, nama acak.
 */

beforeEach(function () {
    Storage::fake(ImageProcessor::DISK);
    Gate::define('settings.view', fn () => true);
    Gate::define('settings.update', fn () => true);
    $this->actingAs(User::factory()->create());
});

/** JPEG asli dengan segmen APP1 (EXIF) berisi teks penanda. */
function jpegWithExif(int $width = 64, int $height = 64): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();

    $payload = "Exif\0\0".'GPS-LOKASI-RAHASIA';
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    $withExif = substr($jpeg, 0, 2).$app1.substr($jpeg, 2);

    $path = tempnam(sys_get_temp_dir(), 'logo').'.jpg';
    file_put_contents($path, $withExif);

    return new UploadedFile($path, 'logo.jpg', 'image/jpeg', null, true);
}

function uploadBranding(string $field, UploadedFile $file)
{
    return test()->from('/pengaturan/branding')->put('/pengaturan/branding', [$field => $file]);
}

test('SEC-052 logo PNG diterima, disimpan dengan nama acak', function () {
    uploadBranding('branding__logo_light', UploadedFile::fake()->image('logo-pakis.png', 400, 200))
        ->assertSessionHasNoErrors();

    $path = setting('branding.logo_light');
    expect($path)->toStartWith('branding/')->not->toContain('logo-pakis');
    Storage::disk(ImageProcessor::DISK)->assertExists($path);
});

test('SEC-052 SVG ditolak', function () {
    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    uploadBranding('branding__logo_light', $svg)->assertSessionHasErrors('branding__logo_light');
    expect(setting('branding.logo_light'))->toBeNull();
});

test('gambar lebih dari 1024 px ditolak', function () {
    uploadBranding('branding__logo_light', UploadedFile::fake()->image('besar.png', 1500, 300))
        ->assertSessionHasErrors('branding__logo_light');
});

test('favicon harus PNG persegi 32–512 px', function () {
    uploadBranding('branding__favicon', UploadedFile::fake()->image('fav.png', 64, 32))->assertSessionHasErrors('branding__favicon');
    uploadBranding('branding__favicon', UploadedFile::fake()->image('fav.jpg', 64, 64))->assertSessionHasErrors('branding__favicon');
    uploadBranding('branding__favicon', UploadedFile::fake()->image('fav.png', 64, 64))->assertSessionHasNoErrors();
});

test('BR-021 metadata EXIF dibuang saat gambar diunggah', function () {
    $file = jpegWithExif();
    expect(file_get_contents($file->getRealPath()))->toContain('GPS-LOKASI-RAHASIA');

    uploadBranding('branding__logo_light', $file)->assertSessionHasNoErrors();

    $stored = Storage::disk(ImageProcessor::DISK)->get(setting('branding.logo_light'));
    expect($stored)->not->toContain('GPS-LOKASI-RAHASIA')
        ->and(@imagecreatefromstring($stored))->toBeInstanceOf(GdImage::class);
});
