<?php

use App\Domain\Settings\SettingRegistry;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\SecurityEventController;
use App\Http\Controllers\Admin\SequenceController;
use App\Http\Controllers\Admin\SettingController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

/*
 * Route web admin (05 §1): nama `admin.<modul>.<aksi>`, path Bahasa Indonesia.
 * Middleware `active`, `password.changed`, `mfa.enforced` ditambahkan saat dibuat (T-013–T-016).
 */
Route::middleware(['auth'])->name('admin.')->group(function () {
    Route::get('unduhan', [ExportController::class, 'index'])->name('exports.index');
    Route::get('unduhan/{file}', [ExportController::class, 'download'])
        ->middleware('signed')
        ->whereNumber('file')
        ->name('exports.download');

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
