<?php

use App\Http\Controllers\Api\V1\ConfigController;
use Illuminate\Support\Facades\Route;

/*
 * API v1 untuk Pakis Pro (05 §5). Prefix `/api` dari bootstrap/app.php; nama `api.v1.<modul>.<aksi>`.
 * Endpoint ber-token & middleware perangkat/versi ditambahkan di T-031.
 */
Route::prefix('v1')->name('api.v1.')->group(function () {
    // Publik: dibaca aplikasi sebelum login (versi minimum, branding). Tetap dibatasi laju.
    Route::get('config', ConfigController::class)->middleware('throttle:60,1')->name('config');
});
