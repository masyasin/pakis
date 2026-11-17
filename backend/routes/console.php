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

// Izin edar (BR-152, T-071): ubah izin lewat masa berlaku jadi `expired` + pengingat H-60/30/7/0.
// 00.30 waktu LOKAL agar "hari ini" sama dengan tanggal di izin. Idempoten (`reminder_logs`).
Schedule::command('catalog:refresh-registrations')
    ->dailyAt('00:30')
    ->timezone(DisplayTime::timezone())
    ->onOneServer()
    ->withoutOverlapping();

// Penugasan kios bertanggal (BR-167, 15 §11.1 ApplyScheduledAssignments): 00.05 waktu LOKAL menyalin
// penugasan yang mulai hari ini ke cache `customers`. Idempoten, aman diulang/terlewat sehari.
Schedule::command('customers:apply-assignments')
    ->dailyAt('00:05')
    ->timezone(DisplayTime::timezone())
    ->onOneServer()
    ->withoutOverlapping();

// Versi daftar harga (15 §11.1 ActivatePriceVersions, T-075): rapikan status versi yang sudah
// digantikan. Resolusi harga memakai rentang tanggal, jadi jeda 5 menit tidak memengaruhi harga.
Schedule::command('pricing:activate-versions')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// Buku stok (T-077, 15 §11.1). Rekonsiliasi 01.00 waktu LOKAL (BR-154): hanya membaca dan memberi
// tahu Owner & super_admin bila saldo ≠ kartu stok. Reservasi lewat masa berlaku dilepas tiap 15
// menit (BR-155); keduanya idempoten.
Schedule::command('inventory:reconcile')
    ->dailyAt('01:00')
    ->timezone(DisplayTime::timezone())
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('inventory:release-reservations')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// Promo (15 §11.1 EndPromotions, T-076): akhiri promo yang lewat waktu selesai/anggaran habis.
// PromotionEngine sudah mengabaikannya, jadi jeda 15 menit hanya memengaruhi label status.
Schedule::command('promotions:end')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping();
