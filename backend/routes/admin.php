<?php

use App\Domain\Settings\SettingRegistry;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\LookupController;
use App\Http\Controllers\Admin\ReferenceController;
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
