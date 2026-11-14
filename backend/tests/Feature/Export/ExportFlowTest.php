<?php

use App\Jobs\RunExport;
use App\Models\User;
use App\Support\Export\Events\ExportCreated;
use App\Support\Export\Events\ExportReady;
use App\Support\Export\ExportFile;
use App\Support\Export\ExportRunner;
use App\Support\Export\ExportStatus;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingSpec;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Fixtures\Listing\UserExportListing;
use Tests\Fixtures\Listing\UserTestListing;

/*
 * BR-005: ekspor memakai spec, scope, pencarian, filter, dan sort yang sama dengan tampilan.
 */

/**
 * Baca .xlsx menjadi array baris (termasuk header).
 *
 * @return list<list<mixed>>
 */
function readXlsx(string $absolutePath): array
{
    $reader = new Reader;
    $reader->open($absolutePath);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        break;
    }
    $reader->close();

    return $rows;
}

beforeEach(function () {
    Storage::fake(ExportRunner::DISK);
    config(['pakis.exports.test_users' => UserExportListing::class]);

    Route::middleware(['web', 'auth'])->get('admin/__uji/ekspor', fn (Request $r) => app(StartExport::class)->handle($r, 'test_users', $r->user()))
        ->name('admin.__uji.export');

    $this->user = User::factory()->create(['name' => 'Agus Pemilik']);
});

test('BR-005 isi berkas sama dengan tampilan untuk q, filter, dan sort yang sama', function () {
    User::factory()->create(['name' => 'Agus Dua', 'email_verified_at' => now()]);
    User::factory()->unverified()->create(['name' => 'Agus Tiga']);
    User::factory()->create(['name' => 'Budi']);
    User::factory()->create(['name' => UserTestListing::HIDDEN_NAME]);

    $query = ['q' => 'agus', 'filter' => ['verified' => '1'], 'sort' => '-name'];
    $expected = collect(runListing($query)->paginator()->items())->pluck('name')->all();

    $response = $this->actingAs($this->user)->get('/admin/__uji/ekspor?'.http_build_query($query));
    $response->assertOk()->assertDownload();

    $file = ExportFile::query()->sole();
    $rows = readXlsx(Storage::disk(ExportRunner::DISK)->path($file->path));

    expect($rows[0])->toBe(['Nama', 'Email', 'ID'])
        ->and(array_column(array_slice($rows, 1), 0))->toBe($expected)
        ->and($expected)->toBe(['Agus Pemilik', 'Agus Dua'])
        ->and($file->status)->toBe(ExportStatus::Ready)
        ->and($file->row_count)->toBe(2)
        ->and($file->created_by)->toBe($this->user->id)
        ->and($file->expires_at->isFuture())->toBeTrue();
});

test('BR-005 scope ikut diterapkan: baris di luar scope tidak pernah masuk berkas', function () {
    User::factory()->create(['name' => UserTestListing::HIDDEN_NAME]);

    $this->actingAs($this->user)->get('/admin/__uji/ekspor')->assertOk();

    $rows = readXlsx(Storage::disk(ExportRunner::DISK)->path(ExportFile::query()->sole()->path));

    expect(array_column($rows, 0))->not->toContain(UserTestListing::HIDDEN_NAME);
});

test('SEC-051 sel =HYPERLINK diekspor sebagai teks, bukan formula', function () {
    User::factory()->create(['name' => '=HYPERLINK("http://jahat.test","klik")']);

    $this->actingAs($this->user)->get('/admin/__uji/ekspor?sort=name')->assertOk();

    $path = Storage::disk(ExportRunner::DISK)->path(ExportFile::query()->sole()->path);
    $names = array_column(readXlsx($path), 0);

    expect($names)->toContain('\'=HYPERLINK("http://jahat.test","klik")');

    // Pastikan di XML lembar kerja tidak ada elemen formula <f>.
    $zip = new ZipArchive;
    $zip->open($path);
    $sheetXml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    expect($sheetXml)->not->toContain('<f>');
});

test('BR-005 di atas batas sinkron masuk antrean, lalu job menghasilkan berkas yang sama', function () {
    Queue::fake();
    config(['pakis.export.sync_max_rows' => 2]);
    User::factory()->count(3)->create();

    $this->actingAs($this->user)->from('/admin/asal')->get('/admin/__uji/ekspor')
        ->assertRedirect('/admin/asal');

    $file = ExportFile::query()->sole();
    expect($file->status)->toBe(ExportStatus::Queued)
        ->and($file->path)->toBeNull();
    Queue::assertPushed(RunExport::class, fn (RunExport $job) => $job->exportFileId === $file->id);

    Event::fake([ExportReady::class]);
    app()->call([new RunExport($file->id), 'handle']);

    $file->refresh();
    expect($file->status)->toBe(ExportStatus::Ready)
        ->and($file->row_count)->toBe(4);
    Event::assertDispatched(ExportReady::class);
});

test('BR-005 job memakai filter default yang dimatikan pengguna, bukan default', function () {
    Queue::fake();
    config(['pakis.export.sync_max_rows' => 0]);

    // Filter `name` diberi default lewat registry khusus test.
    $listing = new class extends UserExportListing
    {
        public function spec(): ListingSpec
        {
            $spec = parent::spec();
            $spec->filter('name')->default('Agus');

            return $spec;
        }
    };
    app()->instance(UserExportListing::class, $listing);
    User::factory()->create(['name' => 'Budi']);

    // Pengguna mematikan default (filter[name]=) → semua baris, termasuk Budi.
    $this->actingAs($this->user)->get('/admin/__uji/ekspor?filter[name]=');
    $file = ExportFile::query()->sole();
    expect($file->params['filter'])->toHaveKey('name')
        ->and($file->params['filter']['name'])->toBe('');

    app()->call([new RunExport($file->id), 'handle']);
    $names = array_column(readXlsx(Storage::disk(ExportRunner::DISK)->path($file->refresh()->path)), 0);

    expect($names)->toContain('Budi');
});

test('BR-005 di atas batas maksimum ditolak tanpa membuat berkas', function () {
    config(['pakis.export.max_rows' => 1]);
    User::factory()->count(2)->create();

    $this->actingAs($this->user)->from('/admin/asal')->get('/admin/__uji/ekspor')
        ->assertRedirect('/admin/asal');

    expect(ExportFile::query()->count())->toBe(0);
});

test('event ExportCreated membawa jumlah baris (untuk audit & mass_export di T-007)', function () {
    Event::fake([ExportCreated::class]);
    User::factory()->count(2)->create();

    $this->actingAs($this->user)->get('/admin/__uji/ekspor')->assertOk();

    Event::assertDispatched(ExportCreated::class, fn (ExportCreated $e) => $e->rowCount === 3);
});

test('job gagal menandai berkas failed', function () {
    $file = ExportFile::query()->create([
        'listing' => 'test_users', 'params' => [], 'status' => ExportStatus::Queued, 'created_by' => $this->user->id,
    ]);

    (new RunExport($file->id))->failed(new RuntimeException('disk penuh'));

    expect($file->refresh()->status)->toBe(ExportStatus::Failed);
});
