<?php

use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/*
 * BR-006 / 05 §4.11: lookup untuk select & autocomplete — selalu terbatas, hanya aktif, ber-izin.
 */

beforeEach(function () {
    $this->seed(ReferenceSeeder::class);
    $this->user = User::factory()->create(['name' => 'Penguji Lookup']);
});

function addReferenceItems(int $count, string $group = 'location_type'): void
{
    foreach (range(1, $count) as $i) {
        ReferenceItem::query()->create([
            'group_code' => $group, 'code' => sprintf('uji_%02d', $i), 'name' => sprintf('Uji %02d', $i), 'sort_order' => 100 + $i,
        ]);
    }
}

// --- Kerangka ---------------------------------------------------------------------------------

test('BR-006 tanpa q maksimal 20 hasil teratas, tidak pernah seluruh tabel, dan LIMIT di SQL', function () {
    addReferenceItems(30);
    DB::enableQueryLog();

    $response = $this->actingAs($this->user)->getJson('/lookup/references?group=location_type')->assertOk();

    expect($response->json())->toHaveCount(20)
        ->and($response->json('0'))->toBe(['value' => ReferenceItem::query()->where('code', 'office')->value('id'), 'label' => 'Kantor', 'hint' => 'office']);

    $select = collect(DB::getQueryLog())->pluck('query')->first(fn ($sql) => str_contains($sql, 'from `reference_items`'));
    expect($select)->toContain('limit 20');
});

test('BR-006 limit di atas 20 ditolak, limit kecil dihormati', function () {
    addReferenceItems(10);

    $this->actingAs($this->user)->getJson('/lookup/references?group=location_type&limit=50')
        ->assertUnprocessable()->assertJsonValidationErrors('limit');

    $this->actingAs($this->user)->getJson('/lookup/references?group=location_type&limit=5')
        ->assertOk()->assertJsonCount(5);
});

test('sumber tak dikenal → 404', function () {
    $this->actingAs($this->user)->getJson('/lookup/rahasia')->assertNotFound();
    $this->actingAs($this->user)->getJson('/lookup/Users')->assertNotFound();
});

test('tamu diarahkan ke login', function () {
    // Test terpisah: actingAs() di test lain tetap berlaku untuk request berikutnya.
    $this->get('/lookup/references?group=location_type')->assertRedirect('/login');
});

test('ids[] mengembalikan label nilai terpilih saja (untuk chip filter)', function () {
    $store = ReferenceItem::query()->where('code', 'store')->sole();

    $this->actingAs($this->user)->getJson("/lookup/references?group=location_type&ids[]={$store->id}")
        ->assertOk()
        ->assertExactJson([['value' => $store->id, 'label' => 'Toko', 'hint' => 'store']]);

    $this->actingAs($this->user)->getJson('/lookup/references?group=location_type&ids[]=abc')
        ->assertUnprocessable()->assertJsonValidationErrors('ids.0');
});

// --- Sumber references ------------------------------------------------------------------------

test('references: grup wajib & harus dikenal', function (string $query) {
    $this->actingAs($this->user)->getJson('/lookup/references'.$query)
        ->assertUnprocessable()->assertJsonValidationErrors('group');
})->with(['', '?group=rahasia']);

test('references: hanya item aktif, urut sort_order, cari kode/nama', function () {
    ReferenceItem::query()->where('code', 'warehouse')->update(['is_active' => false]);

    $this->actingAs($this->user)->getJson('/lookup/references?group=location_type')
        ->assertOk()
        ->assertJsonPath('*.label', ['Kantor', 'Toko', 'Lainnya']);

    $this->actingAs($this->user)->getJson('/lookup/references?group=employment_status&q=kont')
        ->assertJsonPath('*.label', ['Kontrak']);

    $this->actingAs($this->user)->getJson('/lookup/references?group=employment_status&q=daily')
        ->assertJsonPath('*.label', ['Harian Lepas']);
});

// --- Sumber users -----------------------------------------------------------------------------

test('users: tanpa audit.view atau users.view → 403', function () {
    $this->actingAs($this->user)->getJson('/lookup/users')->assertForbidden();
});

test('users: pemegang audit.view atau users.view bisa mencari nama/email', function (string $ability) {
    Gate::define($ability, fn () => true);
    User::factory()->create(['name' => 'Siti Aminah', 'email' => 'siti@pakis-demo.test']);
    User::factory()->create(['name' => 'Budi Santoso', 'email' => 'budi@pakis-demo.test']);

    $this->actingAs($this->user)->getJson('/lookup/users?q=siti')
        ->assertOk()
        ->assertJsonPath('*.label', ['Siti Aminah'])
        ->assertJsonPath('0.hint', 'siti@pakis-demo.test');

    $this->actingAs($this->user)->getJson('/lookup/users?q=budi@pakis')
        ->assertJsonPath('*.label', ['Budi Santoso']);
})->with(['audit.view', 'users.view']);

test('users: karakter wildcard LIKE diperlakukan sebagai teks biasa', function () {
    Gate::define('audit.view', fn () => true);
    User::factory()->create(['name' => 'Agus 100% Hadir']);
    User::factory()->create(['name' => 'Agus Biasa']);

    $this->actingAs($this->user)->getJson('/lookup/users?q='.urlencode('100%'))
        ->assertJsonPath('*.label', ['Agus 100% Hadir']);

    $this->actingAs($this->user)->getJson('/lookup/users?q=%25%25')
        ->assertJsonCount(0);
});
