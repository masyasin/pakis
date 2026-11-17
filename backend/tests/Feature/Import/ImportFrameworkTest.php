<?php

use App\Domain\Employee\Import\EmployeeImportType;
use App\Domain\Employee\Support\NikCipher;
use App\Domain\Settings\SettingDefinition;
use App\Domain\Settings\SettingRegistry;
use App\Domain\Settings\SettingType;
use App\Models\User;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportRegistry;
use App\Support\Import\ImportRow;
use App\Support\Import\ImportRowStatus;
use App\Support\Import\ImportStatus;
use App\Support\Import\MappingSuggester;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Import\ProductLikeImportType;
use Tests\Support\EmployeeWorld;

/*
 * T-085A — kerangka impor generik `app/Support/Import` (BR-170, PA-15). Tipe uji
 * ProductLikeImportType (tests/Fixtures) hanya mengimplementasikan kontrak ImportType; impor
 * pegawai lama diuji tetap utuh di ImportTest/ImportProcessTest. Data dummy.
 */

require_once __DIR__.'/../../Support/import_helpers.php';

beforeEach(function () {
    Storage::fake('local');
    ProductLikeImportType::$store = [];
    $this->world = EmployeeWorld::make($this);
    $this->hrd = $this->world->actor('hrd');
    $this->stocker = User::factory()->withTwoFactor()->create();
    $stocker = $this->stocker;
    Gate::define(ProductLikeImportType::PERMISSION, fn (User $u) => $u->is($stocker));
    // Tipe ber-slug = tipe master: butuh izin payung master_imports.manage juga (T-085, PA-70).
    Gate::define('master_imports.manage', fn (User $u) => $u->is($stocker));
});

/** Unggah ke tipe uji lalu kembalikan batch terakhir. */
function fwUpload(User $actor, string $csv): ImportBatch
{
    test()->actingAs($actor)->post('/impor/uji-produk', ['file' => impCsv($csv, 'stok.csv')])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    return ImportBatch::query()->latest('id')->firstOrFail();
}

function fwMapAndProcess(User $actor, ImportBatch $batch): ImportBatch
{
    $columns = MappingSuggester::suggest(ImportRegistry::forBatch($batch), $batch->headers())['columns'];
    test()->actingAs($actor)->put("/impor/{$batch->id}/pemetaan", ['columns' => $columns])
        ->assertSessionHasNoErrors()->assertRedirect("/impor/{$batch->id}/baris");
    test()->actingAs($actor)->post("/impor/{$batch->id}/proses", ['update_existing' => false])
        ->assertSessionHasNoErrors();

    return $batch->refresh();
}

test('BR-170 tipe uji berjalan penuh hanya lewat kontrak: unggah, pemetaan, validasi, duplikat, proses, impor ulang = 0 baris baru', function () {
    $longBatch = str_repeat('B', 60);
    $csv = "Laporan stok gudang (data dummy)\n\nLokasi Stok;SKU;No Batch;Rak;Jumlah\n"
        ."GD-UTAMA-KUDUS-01;pst-001;{$longBatch};RAK-A-01-TINGKAT-3;10\n"
        ."GD-UTAMA-KUDUS-01;PST-001;{$longBatch};RAK-A-01-TINGKAT-3;4\n"   // duplikat (SKU dinormalkan)
        ."GD-UTAMA-KUDUS-01;PST-002;B2;;sepuluh\n"                           // jumlah salah
        ."GD-UTAMA-KUDUS-01;PST-003;B3;;0\n";

    $batch = fwUpload($this->stocker, $csv);
    expect($batch->type)->toBe(ProductLikeImportType::KEY)
        ->and($batch->headerRow())->toBe(3)
        ->and($batch->total_rows)->toBe(4);

    $batch = fwMapAndProcess($this->stocker, $batch);
    expect($batch->status)->toBe(ImportStatus::Completed)
        ->and([$batch->created_rows, $batch->error_rows])->toBe([2, 2])
        ->and(ProductLikeImportType::$store)->toHaveCount(2);

    $rows = ImportRow::query()->where('import_batch_id', $batch->id)->orderBy('row_number')->get()->keyBy('row_number');
    expect($rows[5]->errors['_row'][0])->toBe('Duplikat dengan baris 4 (lokasi, SKU, batch, dan rak sama). Hanya baris pertama yang dipakai.')
        ->and($rows[6]->errors)->toBe(['qty' => ['Jumlah harus bilangan bulat ≥ 0.']]);

    // Kunci gabungan > 100 karakter tersimpan sebagai sha256 (64 hex), bukan teks mentah.
    $rawKey = "GD-UTAMA-KUDUS-01|PST-001|{$longBatch}|RAK-A-01-TINGKAT-3";
    expect(strlen($rawKey))->toBeGreaterThan(100)
        ->and($rows[4]->row_key)->toBe(hash('sha256', $rawKey));

    // Berkas galat memakai label field tipe uji.
    $this->actingAs($this->stocker)->get("/impor/{$batch->id}/galat")->assertOk();

    // Impor ulang berkas yang sama: tidak ada data baru.
    $again = fwMapAndProcess($this->stocker, fwUpload($this->stocker, $csv));
    expect($again->created_rows)->toBe(0)
        ->and($again->skipped_rows)->toBe(2)
        ->and(ProductLikeImportType::$store)->toHaveCount(2);
});

test('BR-170 row_key impor pegawai = sha256 kunci gabungan (NIK)', function () {
    $nik = '3319'.str_pad((string) random_int(0, 999_999_999_999), 12, '0', STR_PAD_LEFT);
    $batch = impUpload($this->hrd, impCsv("Nama Lengkap,JK,Tgl Masuk,Status,Lokasi,Jabatan,NIK\nAndi Uji,L,2024-02-01,Tetap,GD-01,STF,'{$nik}\n"));
    impMapSuggested($this->hrd, $batch);

    $row = ImportRow::query()->where('import_batch_id', $batch->id)->firstOrFail();
    expect($row->row_key)->toBe(hash('sha256', 'nik:'.app(NikCipher::class)->hash($nik)))
        ->and(DB::table('import_rows')->where('id', $row->id)->value('row_key'))->not->toContain($nik);
});

test('BR-170 batas baris per tipe: kunci import.max_rows_<type> bila terdaftar, selain itu import.max_rows', function () {
    setSetting('import.max_rows', 2);
    expect(ImportRegistry::maxRows(ProductLikeImportType::KEY))->toBe(2)
        ->and(ImportRegistry::maxRows(EmployeeImportType::KEY))->toBe(2);

    $this->actingAs($this->stocker)->post('/impor/uji-produk', ['file' => impCsv("SKU;Lokasi;No Batch;Jumlah\nA;L;B;1\nB;L;B;1\nC;L;B;1\n")])
        ->assertSessionHasErrors(['file' => 'Berkas berisi lebih dari 2 baris data. Bagi menjadi beberapa berkas.']);

    // Kunci khusus tipe (mis. import.max_rows_opening_stock milik T-086) dipakai bila terdaftar.
    $registry = new ReflectionProperty(SettingRegistry::class, 'definitions');
    $original = $registry->getValue();
    $definitions = SettingRegistry::all();
    $definitions['import.max_rows_uji_produk'] = new SettingDefinition('import.max_rows_uji_produk', SettingType::Int, 5, 'Uji', min: 1, max: 10);
    $registry->setValue(null, $definitions);
    try {
        expect(ImportRegistry::maxRows(ProductLikeImportType::KEY))->toBe(5)
            ->and(ImportRegistry::maxRows(EmployeeImportType::KEY))->toBe(2);
    } finally {
        $registry->setValue(null, $original);
    }
});

test('BR-170 rute /impor/{type} hanya untuk slug terdaftar; rute impor pegawai tetap ke controller lama', function () {
    expect(Route::getRoutes()->getByName('admin.master-imports.create')?->wheres['type'])->toBe(ImportRegistry::routePattern())
        // T-085: empat tipe master Paket A + tipe uji.
        ->and(explode('|', ImportRegistry::routePattern()))->toEqualCanonicalizing(['produk', 'kios', 'harga', preg_quote('stok-minimum', '/'), preg_quote('uji-produk', '/')]);

    $this->actingAs($this->stocker)->get('/impor/tidak-ada/baru')->assertNotFound();
    $this->actingAs($this->stocker)->get('/impor/uji-produk/baru')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('imports/create')
            ->where('importType.label', 'Produk Uji')
            ->where('importType.store_url', '/impor/uji-produk')
            ->has('fields', 5));
    $this->actingAs($this->stocker)->get('/impor/uji-produk/templat')->assertOk();

    // Tanpa izin tipe → 403 (pegawai HRD tidak otomatis boleh impor stok uji).
    $this->actingAs($this->hrd)->get('/impor/uji-produk/baru')->assertForbidden();

    // Rute lama tidak berubah tujuan.
    expect(Route::getRoutes()->match(Request::create('/impor/pegawai/baru'))->getName())->toBe('admin.imports.create')
        ->and(Route::getRoutes()->match(Request::create('/impor/profil'))->getName())->toBe('admin.import-profiles.index')
        ->and(Route::getRoutes()->match(Request::create('/impor/12'))->getName())->toBe('admin.imports.show')
        ->and(Route::getRoutes()->match(Request::create('/impor/pegawai', 'POST'))->getName())->toBe('admin.imports.store');
    $this->actingAs($this->hrd)->get('/impor/pegawai/baru')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('imports/create')->missing('importType'));
});

test('BR-170 langkah per batch memakai izin tipe batch: tanpa izin 403, batch orang lain tak terlihat 404', function () {
    $batch = fwUpload($this->stocker, "SKU;Lokasi;No Batch;Jumlah\nA;L;B;1\n");

    // HRD (scope semua) melihat batch, tetapi tidak memegang izin tipe uji.
    $this->actingAs($this->hrd)->get("/impor/{$batch->id}/pemetaan")->assertForbidden();
    // Pengguna lain dengan izin tipe uji tetapi bukan pengunggah & scope bukan `all` → 404.
    $other = User::factory()->withTwoFactor()->create();
    Gate::define(ProductLikeImportType::PERMISSION, fn (User $u) => true);
    $this->actingAs($other)->get("/impor/{$batch->id}/pemetaan")->assertNotFound();
    $this->actingAs($this->stocker)->get("/impor/{$batch->id}/pemetaan")->assertOk();
});

test('BR-170 migrasi row_key: kunci mentah lama menjadi sha256', function () {
    $user = User::factory()->create();
    $batchId = DB::table('import_batches')->insertGetId([
        'type' => EmployeeImportType::KEY, 'original_name' => 'lama.xlsx', 'stored_path' => 'imports/lama.xlsx',
        'file_hash' => str_repeat('0', 64), 'status' => ImportStatus::Completed->value, 'created_by' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('import_rows')->insert([
        ['import_batch_id' => $batchId, 'row_number' => 2, 'row_key' => 'no:pg-001', 'data' => '{}', 'status' => ImportRowStatus::Created->value],
        ['import_batch_id' => $batchId, 'row_number' => 3, 'row_key' => null, 'data' => '{}', 'status' => ImportRowStatus::Invalid->value],
    ]);

    (require database_path('migrations/2026_11_16_210000_hash_import_row_keys.php'))->hashExisting();

    expect(DB::table('import_rows')->where('import_batch_id', $batchId)->orderBy('row_number')->pluck('row_key')->all())
        ->toBe([hash('sha256', 'no:pg-001'), null]);
});
