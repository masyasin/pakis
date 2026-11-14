<?php

use App\Domain\Settings\SettingStore;

if (! function_exists('setting')) {
    /**
     * Nilai site setting bertipe benar, mis. `setting('attendance.default_radius_m')` → int.
     * Kunci harus terdaftar di SettingRegistry (BR-020). Parameter bisnis WAJIB dibaca lewat sini,
     * bukan konstanta (CLAUDE.md §3).
     */
    function setting(string $key): mixed
    {
        return app(SettingStore::class)->get($key);
    }
}
