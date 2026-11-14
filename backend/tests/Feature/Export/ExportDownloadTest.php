<?php

use App\Models\User;
use App\Support\Export\ExportFile;
use App\Support\Export\ExportRunner;
use App\Support\Export\ExportStatus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * SEC-051 / 05 §4.7: berkas ekspor privat, diunduh lewat signed URL berumur pendek, hanya pemilik.
 */

beforeEach(function () {
    Storage::fake(ExportRunner::DISK);
    $this->owner = User::factory()->create();
    Storage::disk(ExportRunner::DISK)->put('exports/uji.xlsx', 'isi');

    $this->file = ExportFile::query()->create([
        'listing' => 'test_users',
        'params' => [],
        'status' => ExportStatus::Ready,
        'path' => 'exports/uji.xlsx',
        'row_count' => 1,
        'expires_at' => now()->addDays(7),
        'created_by' => $this->owner->id,
    ]);
});

function signedDownload(ExportFile $file): string
{
    return URL::temporarySignedRoute('admin.exports.download', now()->addMinutes(15), ['file' => $file->id]);
}

test('SEC-051 pemilik dengan tautan bertanda tangan bisa mengunduh', function () {
    $this->actingAs($this->owner)->get(signedDownload($this->file))
        ->assertOk()
        ->assertDownload($this->file->downloadName());
});

test('SEC-051 pengguna lain dengan tautan sah tetap 404', function () {
    $other = User::factory()->create();

    $this->actingAs($other)->get(signedDownload($this->file))->assertNotFound();
});

test('SEC-051 tautan tanpa tanda tangan atau kedaluwarsa ditolak', function () {
    $this->actingAs($this->owner)->get(route('admin.exports.download', $this->file))->assertForbidden();

    $expired = URL::temporarySignedRoute('admin.exports.download', now()->subMinute(), ['file' => $this->file->id]);
    $this->actingAs($this->owner)->get($expired)->assertForbidden();
});

test('berkas belum siap atau melewati masa simpan → 404', function (array $state) {
    $this->file->forceFill($state)->save();

    $this->actingAs($this->owner)->get(signedDownload($this->file))->assertNotFound();
})->with([
    'antre' => [['status' => ExportStatus::Queued]],
    'gagal' => [['status' => ExportStatus::Failed]],
    'kedaluwarsa' => [['expires_at' => now()->subDay()]],
]);

test('tamu diarahkan ke login', function () {
    $this->get(signedDownload($this->file))->assertRedirect('/login');
});

test('halaman Unduhan hanya menampilkan berkas milik sendiri', function () {
    $other = User::factory()->create();
    ExportFile::query()->create([
        'listing' => 'rahasia_orang_lain', 'params' => [], 'status' => ExportStatus::Ready,
        'created_by' => $other->id,
    ]);

    $this->actingAs($this->owner)->get('/unduhan')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('exports/index')
            ->has('rows', 1)
            ->where('rows.0.listing', 'test_users')
            ->where('rows.0.download_url', fn ($url) => str_contains((string) $url, 'signature=')));
});

test('berkas belum siap tidak punya tautan unduh', function () {
    $this->file->forceFill(['status' => ExportStatus::Processing])->save();

    $this->actingAs($this->owner)->get('/unduhan')
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.download_url', null));
});
