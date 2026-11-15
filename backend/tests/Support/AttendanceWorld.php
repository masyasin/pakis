<?php

namespace Tests\Support;

use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Device\Support\DeviceTokens;
use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Data uji API absen (T-036) di atas ScheduleWorld: lokasi berkoordinat FIKTIF (bukan titik
 * kantor Pakis), pegawai dengan HP aktif + token perangkat, pembuat payload multipart 05 §5.4,
 * dan selfie JPEG buatan GD (dengan segmen EXIF sengaja agar pembuangannya teruji).
 */
final class AttendanceWorld
{
    /** Titik fiktif di laut Jawa; 0,001° lintang ≈ 111 m. */
    public const GUDANG = [-6.5, 110.5];

    public const TOKO = [-6.6, 110.6];

    public ScheduleWorld $s;

    public static function make(TestCase $test): self
    {
        $world = new self;
        $world->s = ScheduleWorld::make($test);
        $world->s->org->gudang->forceFill(['latitude' => self::GUDANG[0], 'longitude' => self::GUDANG[1], 'attendance_radius_m' => 100])->save();
        $world->s->org->toko->forceFill(['latitude' => self::TOKO[0], 'longitude' => self::TOKO[1], 'attendance_radius_m' => 100])->save();

        return $world;
    }

    /**
     * Pegawai aktif + akun role `pegawai` + HP aktif + token perangkat.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{employee: Employee, user: User, token: string, uid: string, device: Device}
     */
    public function phone(?Location $location = null, string $role = 'pegawai', array $attributes = []): array
    {
        $employee = $this->s->org->employee($location ?? $this->s->org->gudang, attributes: ['join_date' => '2026-01-01', ...$attributes]);
        $user = User::factory()->withTwoFactor()->create(['employee_id' => $employee->id])->assignRole($role);
        $uid = hash('sha256', 'absen-'.$employee->id);
        $device = Device::query()->create(['user_id' => $user->id, 'device_uid' => $uid, 'status' => DeviceStatus::Active, 'model' => 'Uji Android']);
        $token = app(DeviceTokens::class)->issue($user, $device)->plainTextToken;

        return ['employee' => $employee, 'user' => $user, 'token' => $token, 'uid' => $uid, 'device' => $device];
    }

    /**
     * Payload sah: absen masuk online di titik gudang, akurasi 10 m, waktu klien = sekarang.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    public static function payload(array $override = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'type' => 'in',
            'client_time' => now()->setTimezone('Asia/Jakarta')->toIso8601String(),
            'is_offline' => '0',
            'latitude' => (string) self::GUDANG[0],
            'longitude' => (string) self::GUDANG[1],
            'accuracy_m' => '10',
            'is_mock' => '0',
            'device_clock_offset_s' => '0',
            'selfie' => self::selfie(),
            ...$override,
        ];
    }

    /**
     * @param  array{token: string, uid: string}  $phone
     * @param  array<string, mixed>  $override
     */
    public static function post(TestCase $test, array $phone, array $override = []): TestResponse
    {
        app('auth')->forgetGuards();

        return $test->post('/api/v1/attendance', self::payload($override), self::headers($phone));
    }

    /** @param  array{token: string, uid: string}  $phone */
    public static function today(TestCase $test, array $phone): TestResponse
    {
        app('auth')->forgetGuards();

        return $test->getJson('/api/v1/attendance/today', self::headers($phone));
    }

    /**
     * @param  array{token: string, uid: string}  $phone
     * @return array<string, string>
     */
    public static function headers(array $phone): array
    {
        return [
            'Accept' => 'application/json',
            'X-App-Version' => '1.0.0',
            'X-Device-Id' => $phone['uid'],
            'Authorization' => 'Bearer '.$phone['token'],
        ];
    }

    /** Titik `$meters` ke utara dari titik dasar (≈ 111 320 m per derajat lintang). */
    public static function north(array $base, float $meters): array
    {
        return [$base[0] + $meters / 111_320, $base[1]];
    }

    /** JPEG asli kecil buatan GD, dengan segmen APP1 "Exif" (berisi teks GPS palsu) disisipkan. */
    public static function jpegBytes(bool $withExif = true): string
    {
        $image = imagecreatetruecolor(64, 64);
        imagefilledrectangle($image, 0, 0, 63, 63, (int) imagecolorallocate($image, 30, 120, 60));
        ob_start();
        imagejpeg($image, null, 80);
        $jpeg = (string) ob_get_clean();

        if (! $withExif) {
            return $jpeg;
        }

        $payload = "Exif\0\0GPSLatitude=UJI-RAHASIA";
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        // Sisipkan tepat setelah SOI (FFD8).
        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    public static function selfie(bool $withExif = true): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('selfie.jpg', self::jpegBytes($withExif));
    }
}
