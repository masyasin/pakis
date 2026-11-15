<?php

/*
 * Sanctum hanya dipakai untuk token API Pakis Pro (T-031, SEC-020). Autentikasi SPA berbasis cookie
 * sengaja dimatikan: web memakai sesi Fortify sendiri, dan API tidak boleh ikut menerima sesi web.
 * Kedaluwarsa token diatur per token dari setting `mobile.token_days`, bukan di sini.
 */
return [
    'stateful' => [],
    'guard' => [],
    'expiration' => null,
    'token_prefix' => '',
    'middleware' => [],
];
