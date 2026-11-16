<?php

use App\Support\Upload\FileKind;
use App\Support\Upload\SecureUpload;
use App\Support\Upload\UploadTarget;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * SEC-109 / PA-22 — komponen unggahan bersama `app/Support/Upload` (T-070). Jenis dikenali dari
 * ISI, foto di-encode ulang (metadata/lokasi terbuang), batas ukuran dari setting `upload.*`.
 */

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->uploads = app(SecureUpload::class);
});

function gdImage(string $kind, int $w = 64, int $h = 48): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 30, 140, 60));
    ob_start();
    match ($kind) {
        'jpg' => imagejpeg($img, null, 85),
        'png' => imagepng($img),
        'webp' => imagewebp($img, null, 80),
    };

    return (string) ob_get_clean();
}

/** Sisipkan segmen APP1 EXIF palsu berisi penanda lokasi tepat setelah SOI. */
function withExif(string $jpeg): string
{
    $payload = "Exif\0\0GPS-LAT-PENANDA-UJI";

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
}

/** Berkas sementara sungguhan (tmpfile() fake kadang tertutup lebih dulu di Windows). */
function realUpload(string $name, string $bytes): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

function expectFieldError(Closure $fn, string $field): void
{
    try {
        $fn();
        test()->fail('Harus ditolak');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
}

test('SEC-109 .jpg berisi skrip/HTML ditolak meski ekstensi & MIME klien jpg', function () {
    $file = realUpload('foto.jpg', '<html><script>alert(1)</script></html>');
    expectFieldError(fn () => $this->uploads->store($file, UploadTarget::productPhoto()), 'photo');

    // Polyglot: header JPEG lalu skrip; GD tidak bisa membacanya sebagai gambar utuh.
    $polyglot = realUpload('foto2.jpg', "\xFF\xD8\xFF\xE0<html><body onload=alert(1)>");
    expectFieldError(fn () => $this->uploads->store($polyglot, UploadTarget::productPhoto()), 'photo');
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('SEC-109 WEBP & PNG diterima sebagai foto; PDF hanya untuk dokumen', function () {
    $webp = $this->uploads->store(UploadedFile::fake()->createWithContent('a.webp', gdImage('webp')), UploadTarget::productPhoto());
    expect($webp->kind)->toBe(FileKind::Webp)->and($webp->path)->toEndWith('.webp');
    Storage::disk('public')->assertExists($webp->path);

    $png = $this->uploads->store(UploadedFile::fake()->createWithContent('b.png', gdImage('png')), UploadTarget::privatePhoto('bukti'));
    expect($png->kind)->toBe(FileKind::Png)->and($png->disk)->toBe('local');

    $pdfBytes = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    $pdf = $this->uploads->store(UploadedFile::fake()->createWithContent('c.pdf', $pdfBytes), UploadTarget::privateDocument('dokumen'));
    expect($pdf->kind)->toBe(FileKind::Pdf);
    expect(Storage::disk('local')->get($pdf->path))->toBe($pdfBytes);

    expectFieldError(fn () => $this->uploads->store(UploadedFile::fake()->createWithContent('d.pdf', $pdfBytes), UploadTarget::productPhoto()), 'photo');
});

test('SEC-109 EXIF/lokasi terbuang setelah simpan; nama berkas acak, bukan nama asli', function () {
    $bytes = withExif(gdImage('jpg'));
    expect($bytes)->toContain('GPS-LAT-PENANDA-UJI');

    $stored = $this->uploads->store(UploadedFile::fake()->createWithContent('rumah-saya.jpg', $bytes), UploadTarget::privatePhoto('bukti'));
    $saved = Storage::disk('local')->get($stored->path);
    expect($saved)->not->toContain('GPS-LAT-PENANDA-UJI')
        ->and($saved)->not->toContain('Exif')
        ->and($stored->path)->not->toContain('rumah-saya')
        ->and($stored->path)->toMatch('#^bukti/\d{4}/\d{2}/[0-9a-z]{26}\.jpg$#');
});

test('SEC-109 batas ukuran mengikuti setting upload.max_image_kb / upload.max_pdf_kb', function () {
    setSetting('upload.max_image_kb', 512);
    setSetting('upload.max_pdf_kb', 512);
    $big = '%PDF-1.4'.str_repeat('A', 600 * 1024)."\n%%EOF";
    expectFieldError(fn () => $this->uploads->store(UploadedFile::fake()->createWithContent('x.pdf', $big), UploadTarget::privateDocument('dok')), 'file');

    setSetting('upload.max_pdf_kb', 1024);
    expect($this->uploads->store(UploadedFile::fake()->createWithContent('x.pdf', $big), UploadTarget::privateDocument('dok'))->kind)
        ->toBe(FileKind::Pdf);
});

test('SEC-109 foto diperkecil ke sisi maksimum target, rasio terjaga', function () {
    $stored = $this->uploads->store(UploadedFile::fake()->createWithContent('besar.png', gdImage('png', 1200, 600)), UploadTarget::productPhoto(400));
    [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($stored->path));
    expect([$w, $h])->toBe([400, 200]);

    $small = $this->uploads->store(UploadedFile::fake()->createWithContent('kecil.png', gdImage('png', 100, 80)), UploadTarget::productPhoto(400));
    expect(getimagesizefromstring(Storage::disk('public')->get($small->path))[0])->toBe(100);
});

test('SEC-109 hapus berkas lewat komponen yang sama; path kosong diabaikan', function () {
    $stored = $this->uploads->store(UploadedFile::fake()->createWithContent('a.png', gdImage('png')), UploadTarget::productPhoto());
    $this->uploads->delete('public', null);
    $this->uploads->delete('public', $stored->path);
    Storage::disk('public')->assertMissing($stored->path);
});
