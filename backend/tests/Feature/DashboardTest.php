<?php

namespace Tests\Feature;

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('admin.dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $user = User::factory()->withTwoFactor()->create()->assignRole('hrd');
        $this->actingAs($user);

        $response = $this->get(route('admin.dashboard'));
        $response->assertOk();
    }

    /** 05 §4.2 + keputusan Mas 16 Nov 2026: dasbor memakai `can:dashboard.view`. */
    public function test_users_without_dashboard_view_are_forbidden()
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->useRealPermissions();
        // Punya menu web lain (lolos `web.access`) tetapi tanpa dashboard.view.
        $role = Role::query()->create(['name' => 'lihat_pegawai', 'label' => 'Lihat pegawai', 'guard_name' => 'web', 'data_scope' => DataScope::All]);
        $role->givePermissionTo('employees.view');
        $this->actingAs(User::factory()->withTwoFactor()->create()->assignRole('lihat_pegawai'));

        $this->get(route('admin.dashboard'))->assertForbidden();
    }
}
