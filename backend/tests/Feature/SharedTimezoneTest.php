<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('zona tampilan general.timezone dibagikan ke frontend (tanggal di browser memakai zona ini)', function () {
    setSetting('general.timezone', 'Asia/Makassar');

    $this->actingAs(User::factory()->create())->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->component('dashboard')->where('timezone', 'Asia/Makassar'));
});

test('zona tampilan juga ada untuk tamu (halaman login)', function () {
    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('timezone', 'Asia/Jakarta'));
});
