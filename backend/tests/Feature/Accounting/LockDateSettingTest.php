<?php

use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/*
 * T-077 (15 §9, PA-19): tipe setting `date` lewat kunci nyata `accounting.lock_date`
 * (berpenanda `locked`: hanya super_admin + konfirmasi password + audit).
 */

beforeEach(function () {
    Gate::define('settings.view', fn () => true);
    Gate::define('settings.update', fn () => true);
});

function lockDateSuperAdmin(): User
{
    Role::query()->firstOrCreate(
        ['name' => RoleCatalog::SUPER_ADMIN, 'guard_name' => 'web'],
        ['label' => 'Super Admin', 'data_scope' => 'all', 'mfa_required' => true, 'is_system' => true],
    );

    return User::factory()->withTwoFactor()->create()->assignRole(RoleCatalog::SUPER_ADMIN);
}

/** Simpan grup accounting lewat form dengan konfirmasi password yang masih berlaku. */
function putAccounting(mixed $lockDate, array $reset = [])
{
    return test()->from('/pengaturan/accounting')
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put('/pengaturan/accounting', ['values' => $lockDate === false ? [] : ['accounting__lock_date' => $lockDate], 'reset' => $reset]);
}

test('T-077 tanggal kunci default kosong (= tidak ada periode terkunci)', function () {
    expect(setting('accounting.lock_date'))->toBeNull();
});

test('T-077 super_admin menyimpan tanggal kunci Y-m-d sebagai string dan perubahan diaudit', function () {
    $super = lockDateSuperAdmin();
    $this->actingAs($super);

    putAccounting('2026-10-31')->assertSessionHasNoErrors();
    app()->forgetScopedInstances();

    $log = AuditLog::query()->where('event', 'setting.updated')->sole();
    expect(setting('accounting.lock_date'))->toBe('2026-10-31')
        ->and($log->old_values)->toBe(['accounting.lock_date' => null])
        ->and($log->new_values)->toBe(['accounting.lock_date' => '2026-10-31'])
        ->and($log->user_id)->toBe($super->id);
});

test('T-077 tipe date menolak format selain Y-m-d dan tanggal mustahil', function (string $value) {
    $this->actingAs(lockDateSuperAdmin());

    putAccounting($value)->assertSessionHasErrors('accounting__lock_date');
    app()->forgetScopedInstances();

    expect(setting('accounting.lock_date'))->toBeNull();
})->with(['31/10/2026', '2026-02-30', '2026-1-5', 'kemarin', '2026-10-31 10:00']);

test('T-077 isian kosong mengosongkan tanggal kunci', function () {
    $this->actingAs(lockDateSuperAdmin());
    setSetting('accounting.lock_date', '2026-10-31');

    putAccounting('')->assertSessionHasNoErrors();
    app()->forgetScopedInstances();

    expect(setting('accounting.lock_date'))->toBeNull();
});

test('T-077 PA-19 selain super_admin tidak bisa mengubah tanggal kunci (403)', function () {
    $this->actingAs(User::factory()->create());

    putAccounting('2026-10-31')->assertForbidden();
    putAccounting(false, ['accounting__lock_date'])->assertSessionHasNoErrors(); // belum dikustom: tanpa perubahan
    app()->forgetScopedInstances();

    expect(setting('accounting.lock_date'))->toBeNull();
});

test('T-077 PA-19 super_admin tanpa konfirmasi password diarahkan ke konfirmasi', function () {
    $this->actingAs(lockDateSuperAdmin());

    $this->get('/pengaturan/accounting')->assertRedirect(route('password.confirm'));
});

test('T-077 halaman Pengaturan mengirim field bertipe date yang terkunci', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/pengaturan/accounting')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('site-settings/edit')
        ->where('fields', fn ($fields) => collect($fields)->firstWhere('key', 'accounting.lock_date')['type'] === 'date'
            && collect($fields)->firstWhere('key', 'accounting.lock_date')['locked'] === true));
});
