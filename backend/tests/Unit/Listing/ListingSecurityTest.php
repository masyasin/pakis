<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * SEC-032: parameter listing tidak pernah masuk mentah ke SQL. Kolom & arah sort hanya dari spec.
 */

/**
 * @param  array<string, mixed>  $query
 * @return list<string> SQL tanpa binding, urut sesuai eksekusi
 */
function capturedSql(array $query): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    runListing($query);

    $sql = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    return $sql;
}

beforeEach(function () {
    User::factory()->count(3)->create();
});

test('SEC-032 parameter jahat tidak mengubah SQL dibanding baseline', function (array $malicious) {
    $baseline = capturedSql([]);
    $attacked = capturedSql($malicious);

    expect($attacked)->toBe($baseline);
})->with([
    'sort kolom sensitif' => [['sort' => 'password']],
    'sort injeksi' => [['sort' => ';drop table users']],
    'sort arah ganda' => [['sort' => 'name desc, password']],
    'filter tak dikenal' => [['filter' => ['x' => '1 OR 1=1']]],
    'filter select injeksi' => [['filter' => ['name' => "x' OR '1'='1"]]],
    'filter relasi injeksi' => [['filter' => ['user_id' => ['1) OR (1=1']]]],
    'per_page raksasa' => [['per_page' => 100000]],
    'filter bukan array' => [['filter' => 'name=Agus']],
]);

test('SEC-032 kolom password tidak pernah muncul di SQL listing', function () {
    $sql = implode("\n", capturedSql(['sort' => 'password', 'filter' => ['password' => 'x'], 'q' => 'password']));

    expect($sql)->not->toContain('`password`');
});

test('SEC-032 nilai pencarian dikirim sebagai binding, bukan bagian SQL', function () {
    $sql = implode("\n", capturedSql(['q' => "agus' OR '1'='1"]));

    expect($sql)->not->toContain("'1'='1")
        ->and($sql)->toContain('like ?');
});
