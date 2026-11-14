<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Numbering\Enums\ResetPeriod;
use App\Domain\Numbering\Models\DocumentSequence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\SequenceSeeder;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * FR-013 / BR-025: halaman Penomoran (05 §4.3), izin `sequences.*` deny-by-default sampai T-018.
 */

beforeEach(function () {
    $this->seed(SequenceSeeder::class);
    $this->user = User::factory()->create();
    $this->employee = DocumentSequence::query()->where('code', 'employee')->sole();
    $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00', 'UTC'));
});

function grantSequences(bool $update = true): void
{
    Gate::define('sequences.view', fn () => true);
    Gate::define('sequences.update', fn () => $update);
}

test('tanpa izin penomoran 403 (deny-by-default sampai T-018)', function () {
    $this->actingAs($this->user)->get('/pengaturan/penomoran')->assertForbidden();
    $this->actingAs($this->user)->put("/pengaturan/penomoran/{$this->employee->id}", [])->assertForbidden();
    $this->actingAs($this->user)->postJson('/pengaturan/penomoran/pratinjau', [])->assertForbidden();
});

test('tamu diarahkan ke login', function () {
    $this->get('/pengaturan/penomoran')->assertRedirect('/login');
});

test('rute penomoran tidak tertangkap halaman Pengaturan', function () {
    Gate::define('settings.view', fn () => true);

    // Tanpa sequences.view → 403 dari route penomoran, bukan 404 "grup tak dikenal" dari Pengaturan.
    $this->actingAs($this->user)->get('/pengaturan/penomoran')->assertForbidden();
    $this->actingAs($this->user)->get('/pengaturan/general')->assertOk();
});

test('daftar menampilkan pola, nomor terakhir, dan contoh nomor berikut', function () {
    grantSequences(update: false);
    $this->employee->counters()->create(['scope_key' => '-', 'last_number' => 7]);

    $this->actingAs($this->user)->get('/pengaturan/penomoran')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sequences/index')
            ->has('rows', 2)
            ->where('rows.0.code', 'attendance_correction')
            ->where('rows.0.last_number', null)
            ->where('rows.0.next', 'KOR-202611-0001')
            ->where('rows.1.code', 'employee')
            ->where('rows.1.pattern', 'PJA-{YY}{SEQ:4}')
            ->where('rows.1.reset_label', 'Tidak pernah')
            ->where('rows.1.last_number', 7)
            ->where('rows.1.next', 'PJA-260008')
            ->has('resetOptions', 4)
            ->where('canUpdate', false));
});

test('cari kode/nama, filter periode reset, dan sort nama', function () {
    grantSequences();

    $this->actingAs($this->user)->get('/pengaturan/penomoran?q=employ')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'employee'));

    $this->actingAs($this->user)->get('/pengaturan/penomoran?filter[reset_period][]=monthly')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'attendance_correction'));

    $this->actingAs($this->user)->get('/pengaturan/penomoran?sort=-name')
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.name', $this->employee->name)
            ->where('listing.state.sort', '-name'));
});

test('BR-025 ubah pola sah tersimpan dan diaudit sequence.updated', function () {
    grantSequences();

    $this->actingAs($this->user)
        ->put("/pengaturan/penomoran/{$this->employee->id}", ['pattern' => ' PJA/{YYYY}/{SEQ:5} ', 'reset_period' => 'yearly'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->employee->refresh();
    expect($this->employee->pattern)->toBe('PJA/{YYYY}/{SEQ:5}')
        ->and($this->employee->reset_period)->toBe(ResetPeriod::Yearly)
        ->and($this->employee->updated_by)->toBe($this->user->id);

    $log = AuditLog::query()->where('event', 'sequence.updated')->sole();
    expect($log->auditable_id)->toBe($this->employee->id)
        ->and($log->old_values)->toBe(['pattern' => 'PJA-{YY}{SEQ:4}', 'reset_period' => 'never'])
        ->and($log->new_values)->toBe(['pattern' => 'PJA/{YYYY}/{SEQ:5}', 'reset_period' => 'yearly']);
});

test('BR-025 pola monthly tanpa {MM} ditolak 422 dan tidak tersimpan', function () {
    grantSequences();

    $this->actingAs($this->user)
        ->from('/pengaturan/penomoran')
        ->put("/pengaturan/penomoran/{$this->employee->id}", ['pattern' => 'PJA-{YYYY}-{SEQ:4}', 'reset_period' => 'monthly'])
        ->assertSessionHasErrors('pattern');

    expect($this->employee->refresh()->pattern)->toBe('PJA-{YY}{SEQ:4}')
        ->and(AuditLog::query()->where('event', 'sequence.updated')->exists())->toBeFalse();
});

test('periode reset di luar enum ditolak', function () {
    grantSequences();

    $this->actingAs($this->user)
        ->put("/pengaturan/penomoran/{$this->employee->id}", ['pattern' => 'PJA-{SEQ:4}', 'reset_period' => 'weekly'])
        ->assertSessionHasErrors('reset_period');
});

test('pemegang sequences.view tanpa sequences.update tidak bisa menyimpan', function () {
    grantSequences(update: false);

    $this->actingAs($this->user)
        ->put("/pengaturan/penomoran/{$this->employee->id}", ['pattern' => 'PJA-{SEQ:4}', 'reset_period' => 'never'])
        ->assertForbidden();
});

test('penomoran yang tidak ada 404', function () {
    grantSequences();

    $this->actingAs($this->user)
        ->put('/pengaturan/penomoran/999999', ['pattern' => 'PJA-{SEQ:4}', 'reset_period' => 'never'])
        ->assertNotFound();
});

test('pratinjau 3 nomor berikut tanpa menaikkan counter', function () {
    grantSequences(update: false);
    $this->employee->counters()->create(['scope_key' => '2026', 'last_number' => 41]);

    $payload = ['sequence_id' => $this->employee->id, 'pattern' => 'PJA/{YYYY}/{SEQ:4}', 'reset_period' => 'yearly'];

    $this->actingAs($this->user)->postJson('/pengaturan/penomoran/pratinjau', $payload)
        ->assertOk()
        ->assertExactJson(['errors' => [], 'numbers' => ['PJA/2026/0042', 'PJA/2026/0043', 'PJA/2026/0044']]);

    expect($this->employee->counters()->where('scope_key', '2026')->value('last_number'))->toBe(41);
});

test('pratinjau pola tidak sah menjawab daftar galat, bukan nomor', function () {
    grantSequences(update: false);

    $response = $this->actingAs($this->user)->postJson('/pengaturan/penomoran/pratinjau', [
        'sequence_id' => $this->employee->id, 'pattern' => 'pja-{SEQ:4}{SEQ:3}', 'reset_period' => 'never',
    ])->assertOk()->assertJsonPath('numbers', []);

    // Huruf kecil di literal + dua {SEQ} → dua galat terpisah, ditampilkan per baris di dialog.
    expect($response->json('errors'))->toHaveCount(2);
});
