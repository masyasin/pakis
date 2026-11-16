<?php

/*
 * Integritas berkas (SEC-070, T-045). Konfigurasi infrastruktur, bukan setting bisnis.
 *
 * - manifest : berkas hash rilis (`php artisan integrity:check --write-manifest` saat deploy, T-046).
 *              Taruh di luar `storage/` dan `public/` agar tidak bisa ditulis lewat celah unggah.
 * - hashed   : folder yang dibandingkan dengan manifest (relatif terhadap base_path).
 * - no_php   : folder yang tidak boleh berisi berkas .php baru (public: dibanding manifest;
 *              storage: tidak boleh ada sama sekali, kecuali view terkompilasi Blade).
 */
return [
    // Akar folder yang diperiksa (test mengarahkannya ke folder sementara).
    'base' => base_path(),
    'manifest' => env('INTEGRITY_MANIFEST', base_path('.integrity-manifest.json')),
    'hashed' => ['app', 'public'],
    'no_php' => ['public', 'storage'],
    // `public/storage` = symlink ke storage/app/public (isi unggahan), bukan kode rilis.
    'ignore' => ['public/storage', 'public/hot'],
];
