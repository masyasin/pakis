<?php

/*
 * Integritas berkas (SEC-070, T-045). Konfigurasi infrastruktur, bukan setting bisnis.
 *
 * - manifest : berkas hash rilis (`php artisan integrity:check --write-manifest` saat deploy, T-046).
 *              Taruh di luar `storage/` dan `public/` agar tidak bisa ditulis lewat celah unggah.
 * - hashed   : folder yang SELURUH isinya dibandingkan dengan manifest (relatif terhadap base).
 * - views    : folder yang hanya view Blade-nya (`*.blade.*`) masuk manifest — sumber sah view
 *              terkompilasi di storage/framework/views (CompiledViewVerifier).
 * - no_php   : folder yang tidak boleh berisi berkas PHP/eksekusi baru (public: dibanding manifest;
 *              storage: tidak boleh ada sama sekali, kecuali view terkompilasi Blade yang sah).
 * Folder yang tidak ada atau berupa symlink = temuan.
 */
return [
    // Akar folder yang diperiksa (test mengarahkannya ke folder sementara).
    'base' => base_path(),
    'manifest' => env('INTEGRITY_MANIFEST', base_path('.integrity-manifest.json')),
    'hashed' => ['app', 'public'],
    'views' => ['resources/views', 'vendor'],
    'no_php' => ['public', 'storage'],
    // `public/storage` = symlink ke storage/app/public (isi unggahan), bukan kode rilis.
    'ignore' => ['public/storage', 'public/hot'],
];
