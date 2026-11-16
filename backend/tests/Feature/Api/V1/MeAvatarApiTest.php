<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AttendanceWorld;

/*
 * T-043 — Ganti foto profil dari Pakis Pro (05 §5.1 `api.v1.me.update`). PHP tidak membaca body
 * multipart pada PUT, jadi HP mengirim `POST /api/v1/me` + `_method=PUT` (method spoofing Laravel).
 * Jalur ini harus tetap melewati token + EnsureDeviceBound seperti PUT biasa. Data fiktif.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->world = AttendanceWorld::make($this);
});

function avatarJpeg(): UploadedFile
{
    $image = imagecreatetruecolor(64, 64);
    ob_start();
    imagejpeg($image);

    return UploadedFile::fake()->createWithContent('foto.jpg', (string) ob_get_clean());
}

test('FR-030 MeAvatar: POST + _method=PUT multipart menyimpan foto profil dan /me melaporkan has_avatar', function () {
    $phone = $this->world->phone();

    app('auth')->forgetGuards();
    $this->post('/api/v1/me', ['_method' => 'PUT', 'avatar' => avatarJpeg()], AttendanceWorld::headers($phone))
        ->assertOk();

    $path = User::query()->findOrFail($phone['user']->id)->avatar_path;
    expect($path)->not->toBeNull();
    Storage::disk('local')->assertExists($path);

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/me', AttendanceWorld::headers($phone))->assertOk()->assertJsonPath('data.has_avatar', true);
});

test('SEC-020 MeAvatar: jalur method spoofing tetap menolak perangkat lain (DEVICE_MISMATCH)', function () {
    $phone = $this->world->phone();
    $headers = [...AttendanceWorld::headers($phone), 'X-Device-Id' => hash('sha256', 'hp-lain')];

    app('auth')->forgetGuards();
    $this->post('/api/v1/me', ['_method' => 'PUT', 'avatar' => avatarJpeg()], $headers)
        ->assertStatus(401)->assertJsonPath('error.code', 'DEVICE_MISMATCH');
    expect(User::query()->findOrFail($phone['user']->id)->avatar_path)->toBeNull();
});
