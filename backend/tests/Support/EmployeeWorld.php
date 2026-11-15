<?php

namespace Tests\Support;

use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Position;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SequenceSeeder;
use Illuminate\Foundation\Testing\TestCase;

/**
 * Data uji modul pegawai (T-024): dua lokasi, satu departemen, dua jabatan, dan pembuat payload
 * form. Semua data fiktif (CLAUDE.md §6); NIK uji dibuat acak saat test berjalan.
 */
final class EmployeeWorld
{
    public Location $gudang;

    public Location $toko;

    public Department $department;

    public Position $staff;

    public Position $head;

    public int $permanent;

    public static function make(TestCase $test): self
    {
        $test->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, SequenceSeeder::class]);

        $world = new self;
        $type = (int) ReferenceItem::query()->forGroup(ReferenceGroup::LocationType)->where('code', 'warehouse')->value('id');
        $world->gudang = Location::query()->create(['code' => 'GD-01', 'name' => 'Gudang Uji', 'location_type_id' => $type]);
        $world->toko = Location::query()->create(['code' => 'TK-01', 'name' => 'Toko Uji', 'location_type_id' => $type]);
        $world->department = Department::query()->create(['code' => 'GDG', 'name' => 'Gudang & Logistik']);
        $world->head = Position::query()->create(['code' => 'KA-GDG', 'name' => 'Kepala Gudang', 'department_id' => $world->department->id, 'level' => 3]);
        $world->staff = Position::query()->create(['code' => 'STF', 'name' => 'Staf Gudang', 'department_id' => $world->department->id, 'parent_position_id' => $world->head->id]);
        $world->permanent = (int) ReferenceItem::query()->forGroup(ReferenceGroup::EmploymentStatus)->where('code', 'permanent')->value('id');

        return $world;
    }

    /** Pegawai dengan penugasan utama aktif. */
    public function employee(?Location $location = null, ?Employee $supervisor = null, array $attributes = []): Employee
    {
        return Employee::factory()->assignedTo($location ?? $this->gudang, $this->staff, $supervisor)->create($attributes);
    }

    /** Pengguna ber-role yang terhubung ke pegawai (MFA aktif agar role wajib MFA tidak tertahan). */
    public function actor(string $role, ?Employee $employee = null): User
    {
        return User::factory()->withTwoFactor()->create(['employee_id' => $employee?->id])->assignRole($role);
    }

    /**
     * Payload tambah pegawai yang sah (form gabungan).
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    public function payload(array $override = []): array
    {
        return array_replace_recursive([
            'full_name' => '  Budi   Santoso ',
            'gender' => 'L',
            'birth_date' => '1995-04-12',
            'phone' => '0812-3456 7890',
            'email' => 'Budi@Contoh.TEST',
            'employment_status_id' => $this->permanent,
            'join_date' => '2026-01-05',
            'assignment' => [
                'position_id' => $this->staff->id,
                'location_id' => $this->gudang->id,
            ],
        ], $override);
    }

    /** NIK acak 16 digit (kode provinsi 99 tidak ada di Dukcapil), tidak pernah literal di kode. */
    public static function nik(): string
    {
        return '99'.str_pad((string) random_int(0, 99_999_999_999_999), 14, '0', STR_PAD_LEFT);
    }
}
