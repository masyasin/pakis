<?php

use App\Http\Controllers\Dev\ListingDemoController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';

// Halaman uji visual komponen (T-005). Tidak pernah terdaftar di staging/produksi.
if (app()->isLocal()) {
    Route::middleware(['auth'])->get('dev/listing-demo', ListingDemoController::class)
        ->name('dev.listing-demo');
}
