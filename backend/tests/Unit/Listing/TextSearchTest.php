<?php

use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use Tests\Fixtures\Listing\ListingTestSession;

/*
 * BR-002: pencarian teks pada listing.
 */

function makeUser(string $name, string $email): User
{
    return User::factory()->create(['name' => $name, 'email' => $email]);
}

test('BR-002 kolom prefix mencocokkan awal kata, bukan tengah', function () {
    makeUser('Agus Salim', 'a@contoh.test');
    makeUser('Bagus Wirawan', 'b@contoh.test');

    // name = prefix: "agus" cocok dengan "Agus Salim" tetapi tidak dengan "Bagus".
    expect(listedNames(['q' => 'agus']))->toBe(['Agus Salim']);
});

test('BR-002 kolom contains mencocokkan di tengah teks', function () {
    makeUser('Citra', 'citra@gudang-undaan.test');

    expect(listedNames(['q' => 'undaan']))->toBe(['Citra']);
});

test('BR-002 kata ganda = AND antar kata, OR antar kolom', function () {
    makeUser('Agus Salim', 'agus@kudus.test');
    makeUser('Agus Pratama', 'agus@pati.test');
    makeUser('Dewi', 'dewi@kudus.test');

    // "agus" (nama) DAN "kudus" (email): hanya baris yang memenuhi keduanya.
    expect(listedNames(['q' => 'agus kudus']))->toBe(['Agus Salim']);
});

test('BR-002 case-insensitive dan accent-insensitive', function () {
    makeUser('Désa Ayu', 'd@contoh.test');

    expect(listedNames(['q' => 'DESA']))->toBe(['Désa Ayu']);
});

test('BR-002 karakter % _ dan \\ dicari sebagai literal', function () {
    // Kolom `name` = prefix (awal nilai kolom), jadi wildcard diletakkan di awal nama.
    makeUser('50% Diskon Pupuk', 'x1@contoh.test');
    makeUser('500 Diskon Pupuk', 'x2@contoh.test');
    makeUser('A_1 Kode', 'x3@contoh.test');
    makeUser('AB1 Kode', 'x4@contoh.test');
    makeUser('C\\D Jalur', 'x5@contoh.test');
    makeUser('CXD Jalur', 'x6@contoh.test');

    // Tanpa escape: "50%" cocok dengan "500…", "a_" cocok dengan "AB…", "c\d" bermakna lain.
    expect(listedNames(['q' => '50%']))->toBe(['50% Diskon Pupuk'])
        ->and(listedNames(['q' => 'a_1']))->toBe(['A_1 Kode'])
        ->and(listedNames(['q' => 'c\\d']))->toBe(['C\\D Jalur']);
});

test('BR-002 kurang dari 2 karakter diabaikan', function () {
    makeUser('Agus', 'a@contoh.test');
    makeUser('Budi', 'b@contoh.test');

    $result = runListing(['q' => ' a ']);

    expect($result->paginator()->total())->toBe(2)
        ->and($result->state()['q'])->toBe('');
});

test('BR-002 spasi dirapikan dan dipotong 100 karakter', function () {
    $result = runListing(['q' => '  agus    salim  ']);
    expect($result->state()['q'])->toBe('agus salim');

    $long = runListing(['q' => str_repeat('x', 500)]);
    expect(mb_strlen($long->state()['q']))->toBe(100);
});

test('BR-002 pencarian lewat relasi (whereHas)', function () {
    $user = makeUser('Eko', 'eko@contoh.test');
    makeUser('Fajar', 'fajar@contoh.test');
    ListingTestSession::query()->insert([
        'id' => 'sesi-uji-1', 'user_id' => $user->id, 'ip_address' => '10.20.30.40',
        'user_agent' => 'uji', 'payload' => '', 'last_activity' => time(),
    ]);

    expect(listedNames(['q' => '10.20']))->toBe(['Eko']);
});

test('escapeLike meng-escape karakter wildcard', function () {
    expect(TextSearch::escapeLike('50%_a\\b'))->toBe('50\\%\\_a\\\\b');
});
