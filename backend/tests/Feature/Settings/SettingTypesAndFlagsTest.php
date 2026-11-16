<?php

use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Settings\Actions\UpdateSettingGroup;
use App\Domain\Settings\DecimalString;
use App\Domain\Settings\SettingDefinition;
use App\Domain\Settings\SettingRegistry;
use App\Domain\Settings\SettingType;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/*
 * PA-19 (T-067): tipe setting decimal / int_list / money dan penanda locked & one_way.
 *
 * Belum ada kunci produksi yang memakai locked/one_way/decimal/int_list, dan 08 §2 tidak boleh
 * berisi kunci palsu. Karena itu kunci contoh disisipkan ke grup `approval` lewat
 * SettingRegistry::extendForTesting() dan dibersihkan lagi setelah setiap test.
 */

beforeEach(function () {
    SettingRegistry::extendForTesting([
        new SettingDefinition('approval.contoh_desimal', SettingType::Decimal, '5', 'Contoh desimal', min: 0, max: 100),
        new SettingDefinition('approval.contoh_daftar', SettingType::IntList, [90, 60, 30], 'Contoh daftar', min: 1, max: 3),
        new SettingDefinition('approval.contoh_terkunci', SettingType::Bool, false, 'Contoh terkunci', locked: true),
        new SettingDefinition('approval.contoh_satu_arah', SettingType::Bool, true, 'Contoh satu arah', oneWay: true),
    ]);

    Gate::define('settings.view', fn () => true);
    Gate::define('settings.update', fn () => true);
    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);
});

afterEach(function () {
    SettingRegistry::extendForTesting([]);
});

function superAdminUser(): User
{
    // Role dibuat langsung (bukan RoleSeeder) agar test ini tidak bergantung pada katalog izin.
    Role::query()->firstOrCreate(
        ['name' => RoleCatalog::SUPER_ADMIN, 'guard_name' => 'web'],
        ['label' => 'Super Admin', 'data_scope' => 'all', 'mfa_required' => true, 'is_system' => true],
    );

    return User::factory()->withTwoFactor()->create()->assignRole(RoleCatalog::SUPER_ADMIN);
}

/** Simpan grup approval lewat form; `confirmed` = konfirmasi password masih berlaku. */
function putApproval(array $values = [], array $reset = [], bool $confirmed = false)
{
    $request = test()->from('/pengaturan/approval');
    if ($confirmed) {
        $request = $request->withSession(['auth.password_confirmed_at' => time()]);
    }

    return $request->put('/pengaturan/approval', ['values' => $values, 'reset' => $reset]);
}

// --- Tipe ---------------------------------------------------------------------------------------

test('PA-19 money: tersimpan & dibaca sebagai string rupiah berskala 2, tanpa float', function () {
    expect(setting('approval.reauth_amount'))->toBe('50000000.00');

    putApproval(['approval__reauth_amount' => '75000000.5'])->assertSessionHasNoErrors();
    app()->forgetScopedInstances();
    expect(setting('approval.reauth_amount'))->toBe('75000000.50');

    // Angka JSON (bukan string) tetap disimpan sebagai string kanonik.
    app(UpdateSettingGroup::class)->handle('approval', ['approval__reauth_amount' => 1500000], [], [], $this->admin->id);
    app()->forgetScopedInstances();
    expect(setting('approval.reauth_amount'))->toBe('1500000.00');
});

test('PA-19 money: nilai negatif, lebih dari 2 digit sen, atau bukan angka ditolak', function (mixed $value) {
    putApproval(['approval__reauth_amount' => $value])->assertSessionHasErrors('approval__reauth_amount');
})->with(['negatif' => '-1', 'tiga digit sen' => '1.234', 'teks' => 'lima juta', 'format id-ID' => '50.000.000,00', 'notasi ilmiah' => '1e6']);

test('PA-19 decimal: string kanonik, batas min–max dibandingkan tanpa float', function () {
    expect(setting('approval.contoh_desimal'))->toBe('5');

    putApproval(['approval__contoh_desimal' => '07.250'])->assertSessionHasNoErrors();
    app()->forgetScopedInstances();
    expect(setting('approval.contoh_desimal'))->toBe('7.25');

    putApproval(['approval__contoh_desimal' => '100'])->assertSessionHasNoErrors();
    putApproval(['approval__contoh_desimal' => '100.000001'])->assertSessionHasErrors('approval__contoh_desimal');
    putApproval(['approval__contoh_desimal' => '-0.1'])->assertSessionHasErrors('approval__contoh_desimal');
    putApproval(['approval__contoh_desimal' => 'abc'])->assertSessionHasErrors('approval__contoh_desimal');
});

test('PA-19 DecimalString membandingkan & merapikan tanpa float', function () {
    expect(DecimalString::compare('24999999.99', '25000000'))->toBe(-1)
        ->and(DecimalString::compare('25000000.00', '25000000'))->toBe(0)
        ->and(DecimalString::compare('-1.5', '-1.25'))->toBe(-1)
        ->and(DecimalString::compare('0.30', '0.3'))->toBe(0)
        ->and(DecimalString::normalize('-0.00'))->toBe('0')
        ->and(DecimalString::normalize('12', 2))->toBe('12.00')
        ->and(DecimalString::normalize('0012.3400'))->toBe('12.34');
});

test('PA-19 int_list: teks dipisah koma menjadi daftar int; duplikat, bukan angka, & terlalu banyak ditolak', function () {
    expect(setting('approval.contoh_daftar'))->toBe([90, 60, 30]);

    putApproval(['approval__contoh_daftar' => '120, 45'])->assertSessionHasNoErrors();
    app()->forgetScopedInstances();
    expect(setting('approval.contoh_daftar'))->toBe([120, 45]);

    putApproval(['approval__contoh_daftar' => '30, 30'])->assertSessionHasErrors('approval__contoh_daftar.1');
    putApproval(['approval__contoh_daftar' => '30, tiga'])->assertSessionHasErrors('approval__contoh_daftar.1');
    putApproval(['approval__contoh_daftar' => '1, 2, 3, 4'])->assertSessionHasErrors('approval__contoh_daftar');
    putApproval(['approval__contoh_daftar' => ''])->assertSessionHasErrors('approval__contoh_daftar');
});

test('T-067 kunci approval: rentang eskalasi 1–168 jam ditegakkan', function () {
    putApproval(['approval__default_escalation_hours' => 0])->assertSessionHasErrors('approval__default_escalation_hours');
    putApproval(['approval__default_escalation_hours' => 169])->assertSessionHasErrors('approval__default_escalation_hours');
    putApproval(['approval__default_escalation_hours' => 48, 'approval__auto_approve_when_no_rule' => '0'])->assertSessionHasNoErrors();
    app()->forgetScopedInstances();

    expect(setting('approval.default_escalation_hours'))->toBe(48)
        ->and(setting('approval.auto_approve_when_no_rule'))->toBeFalse();
});

// --- Penanda locked -----------------------------------------------------------------------------

test('PA-19 locked: selain super_admin ditolak 403 dan nilai tidak berubah', function () {
    putApproval(['approval__contoh_terkunci' => '1'], confirmed: true)->assertForbidden();
    putApproval([], ['approval__contoh_terkunci'], confirmed: true)->assertSessionHasNoErrors(); // belum dikustom: reset = tanpa perubahan
    app()->forgetScopedInstances();

    expect(setting('approval.contoh_terkunci'))->toBeFalse()
        ->and(AuditLog::query()->where('event', 'setting.updated')->count())->toBe(0);
});

test('PA-19 locked: selain super_admin tetap bisa menyimpan kunci lain selama nilai terkunci tidak diubah', function () {
    // Form selalu mengirim semua field grup, termasuk field terkunci dengan nilai lamanya.
    putApproval(['approval__contoh_terkunci' => '0', 'approval__default_escalation_hours' => 12])->assertSessionHasNoErrors();
    app()->forgetScopedInstances();

    expect(setting('approval.default_escalation_hours'))->toBe(12);
});

test('PA-19 locked: super_admin wajib konfirmasi password sebelum membuka & menyimpan grup', function () {
    $this->actingAs(superAdminUser());

    $this->get('/pengaturan/approval')->assertRedirect(route('password.confirm'));
    putApproval(['approval__contoh_terkunci' => '1'])->assertRedirect(route('password.confirm'));
    app()->forgetScopedInstances();

    expect(setting('approval.contoh_terkunci'))->toBeFalse();
});

test('PA-19 locked: Action menolak perubahan super_admin tanpa konfirmasi password (bukan hanya controller)', function () {
    $super = superAdminUser();

    expect(fn () => app(UpdateSettingGroup::class)->handle('approval', ['approval__contoh_terkunci' => true], [], [], $super->id))
        ->toThrow(ValidationException::class);
    app()->forgetScopedInstances();

    expect(setting('approval.contoh_terkunci'))->toBeFalse();
});

test('PA-19 locked: super_admin dengan konfirmasi password bisa mengubah dan perubahan diaudit', function () {
    $super = superAdminUser();
    $this->actingAs($super);

    putApproval(['approval__contoh_terkunci' => '1'], confirmed: true)->assertSessionHasNoErrors();
    app()->forgetScopedInstances();

    $log = AuditLog::query()->where('event', 'setting.updated')->sole();
    expect(setting('approval.contoh_terkunci'))->toBeTrue()
        ->and($log->old_values)->toBe(['approval.contoh_terkunci' => false])
        ->and($log->new_values)->toBe(['approval.contoh_terkunci' => true])
        ->and($log->user_id)->toBe($super->id);
});

test('PA-19 halaman Pengaturan mengirim penanda locked/one_way dan hak ubah kunci terkunci', function () {
    $this->get('/pengaturan/approval')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('site-settings/edit')
        ->where('canChangeLocked', false)
        ->where('fields', fn ($fields) => collect($fields)->firstWhere('key', 'approval.contoh_terkunci')['locked'] === true
            && collect($fields)->firstWhere('key', 'approval.contoh_satu_arah')['one_way'] === true
            && collect($fields)->firstWhere('key', 'approval.reauth_amount')['type'] === 'money'));

    $this->actingAs(superAdminUser())->withSession(['auth.password_confirmed_at' => time()])
        ->get('/pengaturan/approval')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canChangeLocked', true));
});

// --- Penanda one_way ----------------------------------------------------------------------------

test('PA-19 one_way: true → false boleh, false → true ditolak', function () {
    putApproval(['approval__contoh_satu_arah' => '0'])->assertSessionHasNoErrors();
    app()->forgetScopedInstances();
    expect(setting('approval.contoh_satu_arah'))->toBeFalse();

    putApproval(['approval__contoh_satu_arah' => '1'])->assertSessionHasErrors('approval__contoh_satu_arah');
    app()->forgetScopedInstances();
    expect(setting('approval.contoh_satu_arah'))->toBeFalse();
});

test('PA-19 one_way: kembalikan default (true) setelah dimatikan juga ditolak', function () {
    setSetting('approval.contoh_satu_arah', false);

    putApproval([], ['approval__contoh_satu_arah'])->assertSessionHasErrors('approval__contoh_satu_arah');
    app()->forgetScopedInstances();

    expect(setting('approval.contoh_satu_arah'))->toBeFalse();
});
