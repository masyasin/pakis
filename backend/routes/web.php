<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Dev\ListingDemoController;
use App\Http\Controllers\TelegramWebhookController;
use App\Support\Export\StartExport;
use App\Support\Listing\Demo\UserDemoListing;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Sistem internal tanpa halaman publik: `/` (tujuan Fortify setelah logout) langsung ke dasbor bila sudah
// masuk, atau ke halaman masuk bila belum. Langsung ke `login` (bukan lewat dasbor) agar tidak ada
// url.intended palsu.
Route::get('/', fn (Request $request) => redirect()->route($request->user() ? 'admin.dashboard' : 'login'))->name('home');

// Lupa password (05 §4.1, T-015). Pembatas BR-016 ada di RequestPasswordReset; verifikasi OTP juga dibatasi.
Route::middleware('guest')->group(function () {
    Route::get('forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [ForgotPasswordController::class, 'store'])->middleware('throttle:30,1')->name('password.email');
    Route::get('reset-password/{token}', [ForgotPasswordController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [ForgotPasswordController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
    Route::get('reset-password-otp', [ForgotPasswordController::class, 'editOtp'])->name('password.otp');
    Route::post('reset-password-otp', [ForgotPasswordController::class, 'updateOtp'])->middleware('throttle:10,1')->name('password.otp.update');
});

// Webhook bot Telegram: tanpa sesi/CSRF, diautentikasi header rahasia (05 §4.1).
Route::post('telegram/webhook', TelegramWebhookController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware('throttle:60,1')
    ->name('telegram.webhook');

Route::middleware(['auth', 'web.access'])->name('admin.')->group(function () {
    // 05 §4.2: path Bahasa Indonesia, nama admin.<modul>.
    // Dasbor (07 §4, T-044): angka dalam scope pengguna.
    Route::get('dasbor', DashboardController::class)->name('dashboard');

    // Notifikasi milik sendiri (05 §4.2, T-029). Jumlah belum dibaca dipolling lonceng tiap 60 detik.
    Route::get('notifikasi', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifikasi/jumlah', [NotificationController::class, 'unreadCount'])->middleware('throttle:120,1')->name('notifications.unread-count');
    Route::post('notifikasi/baca-semua', [NotificationController::class, 'readAll'])->middleware('throttle:30,1')->name('notifications.read-all');
    Route::post('notifikasi/{id}/baca', [NotificationController::class, 'read'])->whereUuid('id')->middleware('throttle:60,1')->name('notifications.read');
});

require __DIR__.'/admin.php';

// Halaman uji visual komponen (T-005). Tidak pernah terdaftar di staging/produksi.
if (app()->isLocal()) {
    Route::middleware(['auth'])->group(function () {
        Route::get('dev/listing-demo', ListingDemoController::class)->name('dev.listing-demo');
        // Contoh route `*.export` (T-006): pola yang sama dipakai modul nanti.
        Route::get('dev/listing-demo/ekspor', fn (Request $request) => app(StartExport::class)
            ->handle($request, 'dev_users_demo', $request->user()))
            ->name('dev.listing-demo.export');
    });

    config(['pakis.exports.dev_users_demo' => UserDemoListing::class]);
}
