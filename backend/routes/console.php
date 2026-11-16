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

// Retensi (T-045, SEC-013, NFR-011). Malam hari waktu LOKAL, berurutan agar tidak berebut disk.
Schedule::command('attendance:purge-selfies')
    ->dailyAt('02:45')
    ->timezone(DisplayTime::timezone())
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('retention:purge')
    ->dailyAt('03:00')
    ->timezone(DisplayTime::timezone())
    ->onOneServer()
    ->withoutOverlapping();

// Berkas ekspor sudah tidak bisa diunduh begitu lewat `expires_at`; job ini menghapus fisiknya.
Schedule::command('exports:expire')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();

// Alert SEC-041: tiap 5 menit memindai jendela 10 menit (tumpang tindih disengaja; event
// dilaporkan sekali lewat penanda id, login gagal per IP diredam satu jam).
Schedule::command('security:scan-alerts')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// Integritas berkas SEC-070 (manifest ditulis saat deploy, T-046).
Schedule::command('integrity:check')
    ->dailyAt('03:15')
    ->timezone(DisplayTime::timezone())
    ->onOneServer()
    ->withoutOverlapping();

// Eskalasi langkah persetujuan (BR-153(6), 15 §11.1): jam KALENDER sejak langkah aktif, jadi tiap
// 15 menit cukup. Idempoten; tidak pernah menyetujui otomatis.
Schedule::command('approvals:escalate')
    ->everyFifteenMinutes()
    ->timezone(DisplayTime::timezone())
    ->onOneServer()
    ->withoutOverlapping();
