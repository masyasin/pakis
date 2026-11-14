<?php

use App\Models\User;

it('mengarahkan tamu dari / ke halaman masuk', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('mengarahkan pengguna yang sudah masuk dari / ke dasbor', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('dashboard'));
});

it('setelah keluar berakhir di halaman masuk, bukan halaman welcome bawaan', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    $this->assertGuest();
    $this->get(route('home'))->assertRedirect(route('login'));
});
