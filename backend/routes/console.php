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
