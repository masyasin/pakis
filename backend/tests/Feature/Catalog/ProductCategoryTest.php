<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * T-069 — kategori produk (FR-A06, BR-151, 15 §4.4/§5). Izin `products.view`/`products.manage`
 * (T-067) diberi lewat Gate di test ini; matriks role-nya diuji PaketAMatrixTest. Data dummy.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    Gate::define('products.view', fn () => true);
    Gate::define('products.manage', fn () => true);
});

/** @return array<string, mixed> */
function categoryPayload(array $overrides = []): array
{
    return [
        'code' => 'INS', 'name' => 'Insektisida', 'parent_id' => null, 'kind' => 'insecticide',
        'requires_batch' => true, 'requires_expiry' => true, 'requires_serial' => false,
        'requires_registration' => true, 'default_bin_zone' => 'pesticide', 'sort_order' => 10, 'is_active' => true,
        ...$overrides,
    ];
}

function makeCategory(string $code, ?ProductCategory $parent = null, array $overrides = []): ProductCategory
{
    $category = new ProductCategory(categoryPayload(['code' => $code, 'name' => "Kategori {$code}", 'parent_id' => $parent?->id, ...$overrides]));
    $category->save();

    return $category;
}

test('BR-151 daftar kategori butuh products.view; ubah butuh products.manage', function () {
    makeCategory('ROOT');
    $this->actingAs($this->user)->get('/kategori-produk')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('product-categories/index')
            ->has('rows', 1)
            ->where('rows.0.kind_label', 'Insektisida')
            ->where('canManage', true));

    Gate::define('products.manage', fn () => false);
    $this->actingAs($this->user)->post('/kategori-produk', categoryPayload(['code' => 'X']))->assertForbidden();

    Gate::define('products.view', fn () => false);
    $this->actingAs($this->user)->get('/kategori-produk')->assertForbidden();
});

test('BR-151 tambah & ubah kategori tercatat audit; kode dinormalkan huruf besar dan unik', function () {
    $this->actingAs($this->user)->post('/kategori-produk', categoryPayload(['code' => ' ins-cair ']))->assertSessionHasNoErrors();
    $category = ProductCategory::query()->sole();
    expect($category->code)->toBe('INS-CAIR')
        ->and($category->created_by)->toBe($this->user->id)
        ->and(AuditLog::query()->where('event', 'product_category.created')->exists())->toBeTrue();

    $this->actingAs($this->user)->put("/kategori-produk/{$category->id}", categoryPayload(['code' => 'INS-CAIR', 'name' => 'Insektisida Cair']))
        ->assertSessionHasNoErrors();
    $audit = AuditLog::query()->where('event', 'product_category.updated')->sole();
    expect($category->refresh()->name)->toBe('Insektisida Cair')
        ->and($audit->new_values)->toBe(['name' => 'Insektisida Cair']);

    $this->actingAs($this->user)->post('/kategori-produk', categoryPayload(['code' => 'ins-cair']))->assertSessionHasErrors('code');
});

test('BR-151 kategori maksimal 3 tingkat; tingkat 4 ditolak', function () {
    $level1 = makeCategory('L1');
    $level2 = makeCategory('L2', $level1);
    $level3 = makeCategory('L3', $level2);

    $this->actingAs($this->user)->post('/kategori-produk', categoryPayload(['code' => 'L3B', 'parent_id' => $level2->id]))->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post('/kategori-produk', categoryPayload(['code' => 'L4', 'parent_id' => $level3->id]))
        ->assertSessionHasErrors(['parent_id' => 'Kategori maksimal 3 tingkat. Pilih induk yang lebih atas.']);
});

test('BR-151 memindah kategori ikut memindah subpohon: total tingkat tetap ≤ 3', function () {
    $a = makeCategory('A');
    $b = makeCategory('B', $a);
    $c = makeCategory('C');
    makeCategory('D', $c); // subpohon C setinggi 2

    // C (+ anak D) di bawah B (tingkat 2) → D menjadi tingkat 4: ditolak.
    $this->actingAs($this->user)->put("/kategori-produk/{$c->id}", categoryPayload(['code' => 'C', 'name' => 'Kategori C', 'parent_id' => $b->id]))
        ->assertSessionHasErrors('parent_id');
    // C di bawah A (tingkat 1) → D tingkat 3: boleh.
    $this->actingAs($this->user)->put("/kategori-produk/{$c->id}", categoryPayload(['code' => 'C', 'name' => 'Kategori C', 'parent_id' => $a->id]))
        ->assertSessionHasNoErrors();
    expect($c->refresh()->parent_id)->toBe($a->id);
});

test('BR-151 induk yang membentuk lingkaran ditolak', function () {
    $a = makeCategory('A');
    $b = makeCategory('B', $a);

    $this->actingAs($this->user)->put("/kategori-produk/{$a->id}", categoryPayload(['code' => 'A', 'name' => 'Kategori A', 'parent_id' => $b->id]))
        ->assertSessionHasErrors(['parent_id' => 'Induk ini berada di bawah kategori yang sedang diubah, sehingga membentuk lingkaran. Pilih induk lain.']);
    $this->actingAs($this->user)->put("/kategori-produk/{$a->id}", categoryPayload(['code' => 'A', 'name' => 'Kategori A', 'parent_id' => $a->id]))
        ->assertSessionHasErrors('parent_id');
});

test('BR-151 wajib ED tanpa wajib batch ditolak (sama dengan CHECK pc_expiry_chk)', function () {
    $this->actingAs($this->user)->post('/kategori-produk', categoryPayload(['code' => 'ALAT', 'requires_batch' => false, 'requires_expiry' => true]))
        ->assertSessionHasErrors('requires_expiry');
    $this->actingAs($this->user)->post('/kategori-produk', categoryPayload(['code' => 'ALAT', 'kind' => 'tool', 'requires_batch' => false, 'requires_expiry' => false, 'requires_serial' => true]))
        ->assertSessionHasNoErrors();

    // Lapisan DB tetap menolak bila aplikasi terlewati.
    expect(fn () => makeCategory('RAW', null, ['requires_batch' => false, 'requires_expiry' => true]))
        ->toThrow(QueryException::class);
});

test('BR-151 kategori bersub-kategori tidak bisa dihapus (nonaktifkan saja); daun terhapus lunak + audit', function () {
    $parent = makeCategory('P');
    $child = makeCategory('C', $parent);

    $this->actingAs($this->user)->delete("/kategori-produk/{$parent->id}")
        ->assertSessionHasErrors(['category' => 'Kategori "Kategori P" masih punya sub-kategori atau sudah dipakai produk sehingga tidak bisa dihapus. Nonaktifkan saja.']);

    $this->actingAs($this->user)->delete("/kategori-produk/{$child->id}")->assertSessionHasNoErrors();
    expect(ProductCategory::query()->find($child->id))->toBeNull()
        ->and(ProductCategory::withTrashed()->find($child->id)?->trashed())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'product_category.deleted')->exists())->toBeTrue();

    // Setelah anaknya dihapus, induk boleh dihapus.
    $this->actingAs($this->user)->delete("/kategori-produk/{$parent->id}")->assertSessionHasNoErrors();
});

test('BR-150 migrasi data menambah satuan & formulasi ke DB yang sudah berisi referensi', function () {
    $this->seed(ReferenceSeeder::class);
    ReferenceItem::query()->whereIn('group_code', ['unit', 'formulation'])->delete();

    (require database_path('migrations/2026_11_17_000200_seed_unit_and_formulation_references.php'))->up();

    expect(ReferenceItem::query()->where('group_code', 'unit')->count())->toBe(12)
        ->and(ReferenceItem::query()->where('group_code', 'formulation')->count())->toBe(9)
        ->and(ReferenceItem::query()->where('group_code', 'unit')->where('code', 'box')->value('meta'))->toBe(['integer_only' => true]);
});

test('BR-151 jenis moluksida & rodentisida (PA-45) diterima', function (string $kind, string $label) {
    $this->actingAs($this->user)->post('/kategori-produk', categoryPayload(['code' => strtoupper($kind), 'name' => $label, 'kind' => $kind]))
        ->assertSessionHasNoErrors();
    expect(ProductCategory::query()->where('kind', $kind)->sole()->kind->label())->toBe($label);
})->with([['molluscicide', 'Moluksida'], ['rodenticide', 'Rodentisida']]);
