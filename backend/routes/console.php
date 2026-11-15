<?php

use App\Support\Time\DisplayTime;
use Illuminate\Support\Facades\Schedule;

// Retensi audit & log keamanan (SEC-040). 02.30 waktu LOKAL: aplikasi berjalan di UTC, tanpa
// timezone() jadwal ini jatuh pukul 09.30 WIB di tengah jam kerja.
Schedule::command('audit:purge')
    ->dailyAt('02:30')
    ->timezone(DisplayTime::timezone())
    ->onOneServer()
    ->withoutOverlapping();

// Status harian absensi (BR-068, T-037): kemarin & hari ini, tiap 15 menit. Jam tutup hari
// (`attendance.daily_close_time`) dibaca resolver saat berjalan, bukan di sini: setting bisa diubah
// kapan saja tanpa restart scheduler (keputusan Mas 15 Nov 2026). Idempoten, aman diulang.
Schedule::command('attendance:resolve-day')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping();
