<?php

use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Tests\Fixtures\Listing\ListingTestUser;
use Tests\Fixtures\Listing\UserTestListing;

/*
 * BR-003: filter bertipe, divalidasi, nilai tidak valid tidak dipakai dan dilaporkan.
 */

beforeEach(function () {
    $this->agus = User::factory()->create(['name' => 'Agus', 'email_verified_at' => now()]);
    $this->budi = User::factory()->unverified()->create(['name' => 'Budi']);
    $this->citra = User::factory()->create(['name' => 'Citra']);
});

test('BR-003 select menyaring dengan nilai di opsi', function () {
    $result = runListing(['filter' => ['name' => 'Budi']]);

    expect(listedNames(['filter' => ['name' => 'Budi']]))->toBe(['Budi'])
        ->and($result->state()['filters'])->toBe(['name' => 'Budi'])
        ->and($result->state()['invalid_filters'])->toBe([]);
});

test('BR-003 select dengan nilai di luar opsi tidak dipakai dan dilaporkan', function () {
    $result = runListing(['filter' => ['name' => 'Penyusup']]);

    expect($result->paginator()->total())->toBe(3)
        ->and($result->state()['filters'])->not->toHaveKey('name')
        ->and($result->state()['invalid_filters'])->toHaveKey('name');
});

test('BR-003 multi select memakai nilai valid dan melaporkan sisanya', function () {
    $result = runListing(['filter' => ['names' => ['Agus', 'Citra', 'Zorro']]]);

    expect(collect($result->paginator()->items())->pluck('name')->all())->toBe(['Agus', 'Citra'])
        ->and($result->state()['filters']['names'])->toBe(['Agus', 'Citra'])
        ->and($result->state()['invalid_filters'])->toHaveKey('names');
});

test('BR-003 boolean menerima 1/0/true/false', function (string $raw, array $expected) {
    expect(listedNames(['filter' => ['verified' => $raw]]))->toBe($expected);
})->with([
    '1' => ['1', ['Agus', 'Citra']],
    'true' => ['true', ['Agus', 'Citra']],
    '0' => ['0', ['Budi']],
    'false' => ['false', ['Budi']],
]);

test('BR-003 boolean tidak valid dilaporkan', function () {
    $result = runListing(['filter' => ['verified' => 'mungkin']]);

    expect($result->paginator()->total())->toBe(3)
        ->and($result->state()['invalid_filters'])->toHaveKey('verified');
});

test('BR-003 rentang tanggal pada kolom timestamp memakai hari lokal', function () {
    // 2026-11-02 23:30 WIB = 2026-11-02 16:30 UTC → masuk hari 2 Nov lokal.
    $inside = User::factory()->create(['name' => 'Dalam', 'created_at' => CarbonImmutable::parse('2026-11-02 16:30:00', 'UTC')]);
    // 2026-11-03 00:30 WIB = 2026-11-02 17:30 UTC → sudah hari 3 Nov lokal.
    User::factory()->create(['name' => 'Luar', 'created_at' => CarbonImmutable::parse('2026-11-02 17:30:00', 'UTC')]);

    config(['pakis.display_timezone' => 'Asia/Jakarta']);
    $names = listedNames(['filter' => ['created_at' => ['from' => '2026-11-02', 'to' => '2026-11-02']]]);

    expect($names)->toBe([$inside->name]);
});

test('BR-003 rentang tanggal tidak valid dilaporkan', function (mixed $raw) {
    $result = runListing(['filter' => ['created_at' => $raw]]);

    expect($result->paginator()->total())->toBe(3)
        ->and($result->state()['invalid_filters'])->toHaveKey('created_at');
})->with([
    'format salah' => [['from' => '02-11-2026']],
    'tanggal mustahil' => [['from' => '2026-02-30']],
    'from > to' => [['from' => '2026-11-10', 'to' => '2026-11-01']],
    'lebih dari 366 hari' => [['from' => '2025-01-01', 'to' => '2026-11-01']],
    'bukan array' => ['2026-11-01'],
]);

test('BR-003 rentang tanggal boleh satu sisi', function () {
    $result = runListing(['filter' => ['created_at' => ['from' => DisplayTime::toLocal(now())->toDateString()]]]);

    expect($result->paginator()->total())->toBe(3)
        ->and($result->state()['invalid_filters'])->toBe([]);
});

test('BR-003 rentang angka', function () {
    $ids = [$this->agus->id, $this->budi->id];

    expect(listedNames(['filter' => ['id_range' => ['min' => min($ids), 'max' => max($ids)]]]))->toBe(['Agus', 'Budi']);

    $invalid = runListing(['filter' => ['id_range' => ['min' => 'abc']]]);
    expect($invalid->state()['invalid_filters'])->toHaveKey('id_range');

    $reversed = runListing(['filter' => ['id_range' => ['min' => 10, 'max' => 1]]]);
    expect($reversed->state()['invalid_filters'])->toHaveKey('id_range');
});

test('BR-003 filter relasi hanya menerima ID di dalam scope', function () {
    $hidden = User::factory()->create(['name' => UserTestListing::HIDDEN_NAME]);

    $result = runListing(['filter' => ['user_id' => [$this->agus->id, $hidden->id, 999999]]]);

    // ID di luar scope dan ID yang tidak ada diperlakukan sama: tidak dipakai, dilaporkan.
    expect(collect($result->paginator()->items())->pluck('name')->all())->toBe(['Agus'])
        ->and($result->state()['filters']['user_id'])->toBe([$this->agus->id])
        ->and($result->state()['invalid_filters'])->toHaveKey('user_id');
});

test('BR-003 filter relasi menolak ID bukan bilangan bulat positif', function () {
    $result = runListing(['filter' => ['user_id' => ['1 OR 1=1']]]);

    expect($result->paginator()->total())->toBe(3)
        ->and($result->state()['invalid_filters'])->toHaveKey('user_id');
});

test('BR-003 key filter tidak dikenal diabaikan tanpa laporan', function () {
    $result = runListing(['filter' => ['tidak_ada' => '1']]);

    expect($result->paginator()->total())->toBe(3)
        ->and($result->state()['filters'])->toBe([])
        ->and($result->state()['invalid_filters'])->toBe([]);
});

test('BR-003 nilai default dipakai bila key tidak ada, dan bisa dimatikan dengan nilai kosong', function () {
    $spec = (new UserTestListing)->spec();
    $spec->filter('name')->default('Agus');

    $request = fn (array $q) => Request::create('/admin/uji', 'GET', $q);
    $run = fn (array $q) => ListingQuery::apply(
        ListingTestUser::query(), $spec, $request($q)
    );

    $withDefault = $run([]);
    expect($withDefault->paginator()->total())->toBe(1)
        ->and($withDefault->state()['filters'])->toBe(['name' => 'Agus']);

    $cleared = $run(['filter' => ['name' => '']]);
    expect($cleared->paginator()->total())->toBe(3)
        ->and($cleared->state()['filters'])->toBe([]);
});
