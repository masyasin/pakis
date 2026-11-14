<?php

/*
 * Konfigurasi aplikasi PAKIS ONE yang bukan secret.
 *
 * Parameter bisnis tidak boleh ditambahkan di sini: tempatnya registri setting (08 §2, T-008).
 * Nilai di berkas ini hanya penyangga sementara sampai registri tersedia.
 */

return [

    // Zona tampilan sementara. Sama dengan default registri `general.timezone` (08 §2).
    // TODO(T-008): DisplayTime membaca setting('general.timezone'), lalu kunci ini dihapus.
    'display_timezone' => 'Asia/Jakarta',

];
