<?php

use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Attendance\Support\SelfieStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AttendanceWorld;

/*
 * Selfie absen (T-036): SEC-022 — JPEG asli (isi, bukan ekstensi), ≤ 300 KB, EXIF/GPS dibuang,
 * nama acak, disk privat. `attendance.require_selfie` (08 §2).
 */

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->useRealPermissions();
    $this->w = AttendanceWorld::make($this);
    $this->phone = $this->w->phone();
    $this->w->s->weekdays($this->phone['employee'], '2026-01-01');
    Carbon::setTestNow(Carbon::parse('2026-11-16 08:00:00', 'Asia/Jakarta')->utc());
});

afterEach(fn () => Carbon::setTestNow());

function pngBytes(): string
{
    $image = imagecreatetruecolor(16, 16);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

test('SEC-022 selfie disimpan di disk privat dengan nama acak, EXIF/GPS dibuang, isi gambar utuh', function () {
    AttendanceWorld::post($this, $this->phone)->assertCreated();

    $path = (string) AttendanceLog::query()->sole()->selfie_path;
    expect($path)->toMatch('#^selfies/2026/11/[0-9a-z]{26}\.jpg$#');

    $stored = Storage::disk('local')->get($path);
    expect($stored)->not->toContain('Exif')
        ->and($stored)->not->toContain('UJI-RAHASIA')
        ->and(substr((string) $stored, 0, 2))->toBe("\xFF\xD8")
        ->and(getimagesizefromstring((string) $stored)[2] ?? null)->toBe(IMAGETYPE_JPEG)
        // Tanpa re-encode: data gambar (mulai SOS) sama persis dengan aslinya.
        ->and(strstr((string) $stored, "\xFF\xDA"))->toBe(strstr(AttendanceWorld::jpegBytes(), "\xFF\xDA"));
});

test('SEC-022 unggah PNG atau berkas palsu berekstensi .jpg → 422, tidak ada log maupun berkas', function (string $bytes) {
    AttendanceWorld::post($this, $this->phone, ['selfie' => UploadedFile::fake()->createWithContent('selfie.jpg', $bytes)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['selfie']]]);

    expect(AttendanceLog::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'PNG' => fn () => pngBytes(),
    'teks palsu' => fn () => 'ini bukan gambar sama sekali',
    'tanda JPEG tanpa isi' => fn () => "\xFF\xD8\xFF\xE0".str_repeat('x', 200),
]);

test('SEC-022 selfie lebih dari 300 KB → 422', function () {
    $big = AttendanceWorld::jpegBytes(withExif: false);
    // Komentar JPEG (COM) besar agar ukuran berkas > 300 KB namun tetap JPEG sah.
    $chunk = str_repeat('a', 65000);
    $com = '';
    for ($i = 0; $i < 5; $i++) {
        $com .= "\xFF\xFE".pack('n', strlen($chunk) + 2).$chunk;
    }
    $bytes = substr($big, 0, 2).$com.substr($big, 2);

    AttendanceWorld::post($this, $this->phone, ['selfie' => UploadedFile::fake()->createWithContent('selfie.jpg', $bytes)])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

test('08 §2 attendance.require_selfie: wajib → tanpa selfie 422; tidak wajib → diterima tanpa berkas', function () {
    $payload = AttendanceWorld::payload();
    unset($payload['selfie']);

    app('auth')->forgetGuards();
    $this->post('/api/v1/attendance', $payload, AttendanceWorld::headers($this->phone))
        ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['selfie']]]);

    setSetting('attendance.require_selfie', false);
    app('auth')->forgetGuards();
    $this->post('/api/v1/attendance', $payload, AttendanceWorld::headers($this->phone))->assertCreated();
    expect(AttendanceLog::query()->sole()->selfie_path)->toBeNull();
});

test('SEC-022 SelfieStore::strip membuang APP1/APP13/COM tanpa re-encode dan mempertahankan data gambar', function () {
    $clean = AttendanceWorld::jpegBytes(withExif: false);
    $iptc = "\xFF\xED".pack('n', 8)."Photo\0";
    $com = "\xFF\xFE".pack('n', 7).'halo!';
    $dirty = substr($clean, 0, 2).$iptc.$com.substr(AttendanceWorld::jpegBytes(), 2);

    $stripped = (string) SelfieStore::strip($dirty);
    expect($stripped)->toBe(SelfieStore::strip($clean))
        ->and($stripped)->not->toContain('Exif')
        ->and($stripped)->not->toContain('Photo')
        ->and($stripped)->not->toContain('halo!')
        // COM bawaan GD ("CREATOR: gd-jpeg") juga metadata → dibuang; APP0 JFIF dipertahankan.
        ->and($stripped)->not->toContain('CREATOR')
        ->and($stripped)->toContain('JFIF')
        ->and(strstr($stripped, "\xFF\xDA"))->toBe(strstr($clean, "\xFF\xDA"))
        ->and(SelfieStore::strip('bukan jpeg'))->toBeNull();
});

test('SEC-022 bom dekompresi: JPEG kecil yang mengaku 60 000 × 60 000 px ditolak 422 sebelum didekode', function () {
    $bytes = AttendanceWorld::jpegBytes(withExif: false);
    // SOF0 = FF C0, panjang (2), presisi (1), tinggi (2), lebar (2).
    $sof = strpos($bytes, "\xFF\xC0");
    expect($sof)->not->toBeFalse();
    $bomb = substr_replace($bytes, pack('nn', 60000, 60000), (int) $sof + 5, 4);

    expect(getimagesizefromstring($bomb)[0] ?? null)->toBe(60000)
        ->and(SelfieStore::isGenuineJpeg($bomb))->toBeFalse();

    AttendanceWorld::post($this, $this->phone, ['selfie' => UploadedFile::fake()->createWithContent('selfie.jpg', $bomb)])
        ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['selfie']]]);
    expect(AttendanceLog::query()->count())->toBe(0);
});
