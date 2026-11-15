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
use Illuminate\Testing\TestResponse;

/**
 * Data uji koreksi presensi (T-040) di atas AttendanceWorld: pegawai gudang ber-shift PAGI
 * (08:00–16:00, Senin–Jumat) dengan atasan langsung, masing-masing punya HP aktif + token.
 * Semua data fiktif (CLAUDE.md §6).
 */
final class CorrectionWorld
{
    public AttendanceWorld $w;

    /** @var array{employee: Employee, user: User, token: string, uid: string} atasan langsung (supervisor) */
    public array $boss;

    /** @var array{employee: Employee, user: User, token: string, uid: string} pegawai yang mengajukan */
    public array $staff;

    public static function make(TestCase $test): self
    {
        $world = new self;
        $world->w = AttendanceWorld::make($test);
        $boss = $world->w->s->org->employee($world->w->s->org->gudang, attributes: ['join_date' => '2026-01-01', 'full_name' => 'Atasan Uji']);
        $world->boss = $world->phoneFor($boss, 'supervisor');
        $world->staff = $world->member($boss);

        return $world;
    }

    /** Pegawai baru ber-HP dengan atasan tertentu, pola PAGI Senin–Jumat. */
    public function member(?Employee $supervisor = null, ?Location $location = null, string $role = 'pegawai'): array
    {
        $employee = $this->w->s->org->employee($location ?? $this->w->s->org->gudang, $supervisor, ['join_date' => '2026-01-01']);
        $this->w->s->weekdays($employee, '2026-01-01');

        return $this->phoneFor($employee, $role);
    }

    /** @return array{employee: Employee, user: User, token: string, uid: string} */
    public function phoneFor(Employee $employee, string $role): array
    {
        $user = User::factory()->withTwoFactor()->create(['employee_id' => $employee->id])->assignRole($role);
        $uid = hash('sha256', 'koreksi-'.$employee->id);
        $device = Device::query()->create(['user_id' => $user->id, 'device_uid' => $uid, 'status' => DeviceStatus::Active, 'model' => 'Uji Android']);
        $token = app(DeviceTokens::class)->issue($user, $device)->plainTextToken;

        return ['employee' => $employee, 'user' => $user, 'token' => $token, 'uid' => $uid];
    }

    /**
     * Payload koreksi sah: lupa Masuk Senin 16 Nov 2026 pukul 07:55.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    public static function payload(array $override = []): array
    {
        return [
            'work_date' => '2026-11-16',
            'type' => 'check_in',
            'proposed_check_in' => '07:55',
            'reason_code' => 'forgot',
            'note' => 'Lupa menekan tombol Masuk',
            ...$override,
        ];
    }

    /**
     * @param  array{token: string, uid: string}  $phone
     * @param  array<string, mixed>  $payload
     */
    public static function submit(TestCase $test, array $phone, array $payload = []): TestResponse
    {
        app('auth')->forgetGuards();

        return $test->post('/api/v1/attendance-corrections', self::payload($payload), AttendanceWorld::headers($phone));
    }

    /**
     * @param  array{token: string, uid: string}  $phone
     * @param  array<string, mixed>  $body
     */
    public static function call(TestCase $test, array $phone, string $method, string $uri, array $body = []): TestResponse
    {
        app('auth')->forgetGuards();
        app()->forgetScopedInstances();

        return $test->json($method, $uri, $body, AttendanceWorld::headers($phone));
    }

    /** PNG 8×8 dengan chunk teks berisi penanda rahasia uji (harus dibuang saat disimpan). */
    public static function pngWithText(): UploadedFile
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $data = "Comment\0UJI-RAHASIA";
        $chunk = pack('N', strlen($data)).'tEXt'.$data.pack('N', crc32('tEXt'.$data));
        // Sisipkan setelah IHDR (signature 8 + IHDR 25 byte).
        $png = substr($png, 0, 33).$chunk.substr($png, 33);

        return UploadedFile::fake()->createWithContent('bukti.png', $png);
    }

    public static function pdf(int $padBytes = 0): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('surat.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n".str_repeat('0', $padBytes)."\ntrailer<<>>\n%%EOF\n");
    }
}
