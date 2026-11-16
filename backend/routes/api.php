<?php

use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendanceCorrectionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Middleware\EnsureAppVersion;
use App\Http\Middleware\EnsureDeviceBound;
use App\Http\Middleware\EnsurePasswordChangedApi;
use Illuminate\Support\Facades\Route;

/*
 * API v1 untuk Pakis Pro (05 §5). Prefix `/api` dari bootstrap/app.php; nama `api.v1.<modul>.<aksi>`.
 * Urutan pengaman endpoint ber-token: versi app (BR-027) → token Sanctum → perangkat terikat
 * (BR-040, SEC-020) → wajib ganti password (SEC-006) → batas laju per token (SEC-068).
 */
Route::prefix('v1')->name('api.v1.')->group(function () {
    // Publik: dibaca aplikasi sebelum login (versi minimum, branding). Tanpa cek versi (BR-027).
    Route::get('config', ConfigController::class)->middleware('throttle:60,1')->name('config');

    Route::middleware(EnsureAppVersion::class)->group(function () {
        Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
        Route::middleware('throttle:password')->group(function () {
            Route::post('auth/password/forgot', [AuthController::class, 'forgot'])->name('auth.password.forgot');
            Route::post('auth/password/reset-otp', [AuthController::class, 'resetOtp'])->name('auth.password.reset-otp');
        });

        Route::middleware(['auth:sanctum', EnsureDeviceBound::class, EnsurePasswordChangedApi::class, 'throttle:api'])->group(function () {
            Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

            Route::get('me', [MeController::class, 'show'])->name('me.show');
            Route::put('me', [MeController::class, 'update'])->name('me.update');
            Route::put('me/password', [MeController::class, 'password'])->name('me.password');
            Route::post('me/sessions/revoke-others', [MeController::class, 'revokeOthers'])->name('me.revoke-others');

            // Absen (05 §5.4, T-036). Unggah absen dibatasi lebih ketat per pengguna (SEC-068).
            Route::middleware('can:attendance.self')->group(function () {
                Route::get('attendance/today', [AttendanceController::class, 'today'])->name('attendance.today');
                // Riwayat & rekap milik sendiri (05 §5.5–5.6, T-043).
                Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
                Route::get('attendance/summary', [AttendanceController::class, 'summary'])->name('attendance.summary');
                Route::post('attendance', [AttendanceController::class, 'store'])->middleware('throttle:attendance')->name('attendance.store');
            });

            // Koreksi presensi (05 §5.7–5.8, T-040). `{id}` milik sendiri / dalam wewenang 06 §6, selain itu 404.
            Route::middleware('can:attendance_corrections.create')->group(function () {
                Route::get('attendance-corrections', [AttendanceCorrectionController::class, 'index'])->name('attendance-corrections.index');
                Route::post('attendance-corrections', [AttendanceCorrectionController::class, 'store'])->name('attendance-corrections.store');
                Route::post('attendance-corrections/{id}/cancel', [AttendanceCorrectionController::class, 'cancel'])->whereNumber('id')->name('attendance-corrections.cancel');
            });
            Route::middleware('can:attendance_corrections.approve')->prefix('approvals')->name('approvals.')->group(function () {
                Route::get('attendance-corrections', [ApprovalController::class, 'corrections'])->name('attendance-corrections.index');
                Route::post('attendance-corrections/{id}/approve', [ApprovalController::class, 'approve'])->whereNumber('id')->name('attendance-corrections.approve');
                Route::post('attendance-corrections/{id}/reject', [ApprovalController::class, 'reject'])->whereNumber('id')->name('attendance-corrections.reject');
            });

            // Notifikasi milik sendiri (05 §5.1, T-043). `{id}` milik orang lain → 404.
            Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
            Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id')->name('notifications.read');
        });
    });
});
