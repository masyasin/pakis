<?php

use App\Http\Controllers\Dev\ListingDemoController;
use App\Support\Export\StartExport;
use App\Support\Listing\Demo\UserDemoListing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
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
