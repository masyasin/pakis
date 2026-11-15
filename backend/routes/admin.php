<?php

use App\Domain\Settings\SettingRegistry;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\ForcePasswordChangeController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\LookupController;
use App\Http\Controllers\Admin\MfaSetupController;
use App\Http\Controllers\Admin\PositionController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\ReferenceController;
use App\Http\Controllers\Admin\RegionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SecurityEventController;
use App\Http\Controllers\Admin\SequenceController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\ShiftController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

/*
 * Route web admin (05 §1): nama `admin.<modul>.<aksi>`, path Bahasa Indonesia.
 * Middleware `active`, `password.changed`, `mfa.enforced` ditambahkan saat dibuat (T-013–T-016).
 */
Route::middleware(['auth', 'web.access'])->name('admin.')->group(function () {
    Route::get('unduhan', [ExportController::class, 'index'])->name('exports.index');
    Route::get('unduhan/{file}', [ExportController::class, 'download'])
        ->middleware('signed')
        ->whereNumber('file')
        ->name('exports.download');

    // Wajib ganti password (05 §4.1, FR-007). Dibiarkan oleh middleware RequirePasswordChange.
    Route::get('ganti-password', [ForcePasswordChangeController::class, 'edit'])->name('password.force');
    Route::put('ganti-password', [ForcePasswordChangeController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.force.update');

    // Profil & Keamanan (05 §4.1, T-017): hanya akun sendiri, tanpa id pengguna di URL. Aksi yang
    // memeriksa password saat ini dibatasi agar tidak bisa dipakai menebak password.
    Route::get('profil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profil', [ProfileController::class, 'update'])->middleware('throttle:10,1')->name('profile.update');
    Route::get('profil/foto', [ProfileController::class, 'avatar'])->name('profile.avatar');
    Route::put('profil/password', [ProfileController::class, 'password'])->middleware('throttle:6,1')->name('profile.password');
    Route::get('profil/keamanan', [ProfileController::class, 'security'])->middleware(RequirePassword::class)->name('profile.security');
    Route::delete('profil/sesi', [ProfileController::class, 'destroyOtherSessions'])->middleware('throttle:10,1')->name('profile.sessions.destroy-others');
    Route::delete('profil/sesi/{session}', [ProfileController::class, 'destroySession'])->middleware('throttle:10,1')->name('profile.sessions.destroy');
    Route::post('profil/telegram', [ProfileController::class, 'linkTelegram'])->middleware('throttle:10,1')->name('profile.telegram.link');
    Route::delete('profil/telegram', [ProfileController::class, 'unlinkTelegram'])->name('profile.telegram.unlink');

    // Role & Hak Akses (05 §4, FR-017). Ubah role/permission = aksi sensitif → konfirmasi password
    // (06 §1.6). Aturan terkunci/role sendiri/role terpakai ada di SaveRole & DeleteRole.
    Route::get('role', [RoleController::class, 'index'])->middleware('can:roles.view')->name('roles.index');
    Route::middleware(['can:roles.manage', RequirePassword::class])->group(function () {
        Route::get('role/baru', [RoleController::class, 'create'])->name('roles.create');
        Route::post('role', [RoleController::class, 'store'])->name('roles.store');
        Route::get('role/{role}/ubah', [RoleController::class, 'edit'])->whereNumber('role')->name('roles.edit');
        Route::put('role/{role}', [RoleController::class, 'update'])->whereNumber('role')->name('roles.update');
        Route::delete('role/{role}', [RoleController::class, 'destroy'])->whereNumber('role')->name('roles.destroy');
    });

    // Pengguna (05 §4.4, FR-016). Permission dasar di route; wewenang atas akun tertentu (bukan diri
    // sendiri, Owner hanya oleh Owner, hak target ⊆ pelaku) di UserPolicy. `{user}` ber-scope data
    // (di luar scope → 404). `ekspor`/`baru` didaftarkan sebelum `{user}`.
    Route::get('pengguna', [UserController::class, 'index'])->middleware('can:users.view')->name('users.index');
    Route::get('pengguna/ekspor', [UserController::class, 'export'])->middleware('can:users.export')->name('users.export');
    Route::middleware('can:users.create')->group(function () {
        Route::get('pengguna/baru', [UserController::class, 'create'])->name('users.create');
        Route::post('pengguna', [UserController::class, 'store'])->middleware('throttle:30,1')->name('users.store');
    });
    Route::get('pengguna/{user}', [UserController::class, 'show'])->middleware('can:users.view')->whereNumber('user')->name('users.show');
    Route::middleware('can:users.update')->group(function () {
        Route::get('pengguna/{user}/ubah', [UserController::class, 'edit'])->whereNumber('user')->name('users.edit');
        Route::put('pengguna/{user}', [UserController::class, 'update'])->whereNumber('user')->name('users.update');
        Route::post('pengguna/{user}/cabut-sesi', [UserController::class, 'revokeSessions'])->whereNumber('user')->middleware('throttle:10,1')->name('users.revoke-sessions');
    });
    Route::patch('pengguna/{user}/status', [UserController::class, 'toggle'])
        ->middleware(['can:users.deactivate', 'throttle:20,1'])->whereNumber('user')->name('users.toggle');
    // Konfirmasi password admin di body request (AdminResetPasswordRequest), dibatasi agar tidak
    // bisa dipakai menebak password admin.
    Route::post('pengguna/{user}/reset-password', [UserController::class, 'resetPassword'])
        ->middleware(['can:users.reset_password', 'throttle:6,1'])->whereNumber('user')->name('users.reset-password');
    Route::post('pengguna/{user}/reset-mfa', [UserController::class, 'resetMfa'])
        ->middleware(['can:users.reset_mfa', 'throttle:10,1'])->whereNumber('user')->name('users.reset-mfa');

    // Shift (05 §4.9, FR-031, BR-060). `{shift}` ber-scope (06 §5). Hapus ditolak bila dipakai.
    Route::get('shift', [ShiftController::class, 'index'])->middleware('can:shifts.view')->name('shifts.index');
    Route::middleware('can:shifts.manage')->group(function () {
        Route::get('shift/baru', [ShiftController::class, 'create'])->name('shifts.create');
        Route::post('shift', [ShiftController::class, 'store'])->name('shifts.store');
        Route::get('shift/{shift}/ubah', [ShiftController::class, 'edit'])->whereNumber('shift')->name('shifts.edit');
        Route::put('shift/{shift}', [ShiftController::class, 'update'])->whereNumber('shift')->name('shifts.update');
        Route::delete('shift/{shift}', [ShiftController::class, 'destroy'])->whereNumber('shift')->name('shifts.destroy');
    });

    // Wajib aktivasi MFA (05 §4.1, BR-015). Dibiarkan oleh middleware EnforceMfa.
    Route::get('aktivasi-mfa', MfaSetupController::class)->middleware(RequirePassword::class)->name('mfa.setup');

    // Lookup select/autocomplete (05 §4.11, BR-006). Izin per sumber dicek di controller.
    Route::get('lookup/{source}', [LookupController::class, 'show'])
        ->where('source', '[a-z_]+')
        ->middleware('throttle:120,1')
        ->name('lookup.show');

    // Penomoran (05 §4.3). Didaftarkan sebelum Pengaturan; `{group}` juga dibatasi ke grup registri.
    Route::get('pengaturan/penomoran', [SequenceController::class, 'index'])
        ->middleware('can:sequences.view')
        ->name('sequences.index');
    Route::post('pengaturan/penomoran/pratinjau', [SequenceController::class, 'preview'])
        ->middleware(['can:sequences.view', 'throttle:120,1'])
        ->name('sequences.preview');
    Route::put('pengaturan/penomoran/{sequence}', [SequenceController::class, 'update'])
        ->middleware('can:sequences.update')
        ->whereNumber('sequence')
        ->name('sequences.update');

    // Wilayah (05 §4.5, FR-021), hanya baca; diisi `php artisan regions:import`.
    Route::get('wilayah', [RegionController::class, 'index'])
        ->middleware('can:regions.view')
        ->name('regions.index');

    // Lokasi (05 §4.5, FR-022, BR-041). `baru` & `ekspor` sebelum `{location}`; lokasi di luar
    // scope 404 lewat route binding ber-scope.
    Route::middleware('can:locations.view')->group(function () {
        Route::get('lokasi', [LocationController::class, 'index'])->name('locations.index');
        Route::get('lokasi/ekspor', [LocationController::class, 'export'])->name('locations.export');
    });
    Route::middleware('can:locations.manage')->group(function () {
        Route::get('lokasi/baru', [LocationController::class, 'create'])->name('locations.create');
        Route::post('lokasi', [LocationController::class, 'store'])->name('locations.store');
        Route::get('lokasi/{location}/ubah', [LocationController::class, 'edit'])->whereNumber('location')->name('locations.edit');
        Route::put('lokasi/{location}', [LocationController::class, 'update'])->whereNumber('location')->name('locations.update');
        Route::delete('lokasi/{location}', [LocationController::class, 'destroy'])->whereNumber('location')->name('locations.destroy');
    });

    // Departemen (05 §4.5, FR-023). `baru` & `ekspor` sebelum `{department}`. Tidak ber-scope data.
    Route::middleware('can:departments.view')->group(function () {
        Route::get('departemen', [DepartmentController::class, 'index'])->name('departments.index');
        Route::get('departemen/ekspor', [DepartmentController::class, 'export'])->name('departments.export');
    });
    Route::middleware('can:departments.manage')->group(function () {
        Route::get('departemen/baru', [DepartmentController::class, 'create'])->name('departments.create');
        Route::post('departemen', [DepartmentController::class, 'store'])->name('departments.store');
        Route::get('departemen/{department}/ubah', [DepartmentController::class, 'edit'])->whereNumber('department')->name('departments.edit');
        Route::put('departemen/{department}', [DepartmentController::class, 'update'])->whereNumber('department')->name('departments.update');
        Route::delete('departemen/{department}', [DepartmentController::class, 'destroy'])->whereNumber('department')->name('departments.destroy');
    });

    // Jabatan (05 §4.5, FR-024, BR-042). `baru` & `ekspor` sebelum `{position}`. Tidak ber-scope data.
    Route::middleware('can:positions.view')->group(function () {
        Route::get('jabatan', [PositionController::class, 'index'])->name('positions.index');
        Route::get('jabatan/ekspor', [PositionController::class, 'export'])->name('positions.export');
    });
    Route::middleware('can:positions.manage')->group(function () {
        Route::get('jabatan/baru', [PositionController::class, 'create'])->name('positions.create');
        Route::post('jabatan', [PositionController::class, 'store'])->name('positions.store');
        Route::get('jabatan/{position}/ubah', [PositionController::class, 'edit'])->whereNumber('position')->name('positions.edit');
        Route::put('jabatan/{position}', [PositionController::class, 'update'])->whereNumber('position')->name('positions.update');
        Route::delete('jabatan/{position}', [PositionController::class, 'destroy'])->whereNumber('position')->name('positions.destroy');
    });

    // Referensi (05 §4.3, BR-026). `urutan` didaftarkan sebelum `{item}`.
    Route::get('referensi', [ReferenceController::class, 'index'])
        ->middleware('can:references.view')
        ->name('references.index');
    Route::middleware('can:references.manage')->group(function () {
        Route::post('referensi', [ReferenceController::class, 'store'])->name('references.store');
        Route::post('referensi/urutan', [ReferenceController::class, 'reorder'])->name('references.reorder');
        Route::put('referensi/{item}', [ReferenceController::class, 'update'])->whereNumber('item')->name('references.update');
        Route::delete('referensi/{item}', [ReferenceController::class, 'destroy'])->whereNumber('item')->name('references.destroy');
    });

    // Pengaturan (05 §4.3). Grup sensitif minta konfirmasi password di controller.
    Route::post('pengaturan/uji-email', [SettingController::class, 'testMail'])
        ->middleware(['can:settings.update', 'throttle:3,1'])
        ->name('settings.test-mail');
    Route::get('pengaturan/{group?}', [SettingController::class, 'edit'])
        ->middleware('can:settings.view')
        ->whereIn('group', array_keys(SettingRegistry::GROUPS))
        ->name('settings.edit');
    Route::put('pengaturan/{group}', [SettingController::class, 'update'])
        ->middleware('can:settings.update')
        ->whereIn('group', array_keys(SettingRegistry::GROUPS))
        ->name('settings.update');

    // Audit & log keamanan (05 §4.4). Ekspor audit = aksi sensitif → konfirmasi ulang password (06 §1.6).
    Route::middleware('can:audit.view')->group(function () {
        Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
        Route::get('audit/ekspor', [AuditLogController::class, 'export'])
            ->middleware(['can:audit.export', RequirePassword::class])
            ->name('audit.export');
        Route::get('audit/{log}', [AuditLogController::class, 'show'])->whereNumber('log')->name('audit.show');

        Route::get('log-keamanan', [SecurityEventController::class, 'index'])->name('security-events.index');
        Route::get('log-keamanan/ekspor', [SecurityEventController::class, 'export'])
            ->middleware(['can:audit.export', RequirePassword::class])
            ->name('security-events.export');
    });
});
