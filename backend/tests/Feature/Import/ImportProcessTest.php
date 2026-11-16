<?php

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Models\Role;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\NikCipher;
use App\Domain\Notification\Notifications\ImportDoneNotification;
use App\Jobs\ProcessImportBatchJob;
use App\Jobs\ValidateImportBatchJob;
use App\Models\User;
use App\Support\Import\Actions\ProcessImportBatch;
use App\Support\Import\Actions\ValidateImportBatch;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportRegistry;
use App\Support\Import\ImportRow;
use App\Support\Import\ImportRowStatus;
use App\Support\Import\ImportStatus;
use App\Support\Import\MappingSuggester;
use App\Support\Import\SpreadsheetReader;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\AssertsIdor;
use Tests\Support\EmployeeWorld;

require_once __DIR__.'/../../Support/import_helpers.php';

uses(AssertsIdor::class);

beforeEach(function () {
    Storage::fake('local');
    $this->world = EmployeeWorld::make($this);
    $this->hrd = $this->world->actor('hrd');
});

/** NIK uji acak (bukan NIK asli), dibuat saat test berjalan. */
function procNik(): string
{
    return '3319'.str_pad((string) random_int(0, 999_999_999_999), 12, '0', STR_PAD_LEFT);
}

const PROC_HEADER = ['Nama', 'JK', 'Tgl Masuk', 'Status', 'Lokasi', 'Jabatan', 'No Pegawai', 'NIK', 'Tanggal Lahir', 'No HP', 'Alamat', 'No Atasan'];

/**
 * Baris data uji (data dummy) sesuai PROC_HEADER.
 *
 * @param  array<string, string>  $o
 * @return list<string>
 */
function procRow(string $name, array $o = []): array
{
    $v = [
        'jk' => 'L', 'masuk' => '01/02/2024', 'status' => 'Tetap', 'lokasi' => 'GD-01', 'jabatan' => 'STF',
        'no' => '', 'nik' => '', 'lahir' => '', 'hp' => '', 'alamat' => '', 'atasan' => '', ...$o,
    ];

    return [$name, $v['jk'], $v['masuk'], $v['status'], $v['lokasi'], $v['jabatan'], $v['no'], $v['nik'], $v['lahir'], $v['hp'], $v['alamat'], $v['atasan']];
}

/** @param  list<list<string>>  $rows */
function procValidated(User $actor, array $rows): ImportBatch
{
    $batch = impUpload($actor, impXlsx([PROC_HEADER, ...$rows]));
    impMapSuggested($actor, $batch);

    return $batch->refresh();
}

function procRun(User $actor, ImportBatch $batch, bool $update = false): ImportBatch
{
    test()->actingAs($actor)->post("/impor/{$batch->id}/proses", ['update_existing' => $update])
        ->assertSessionHasNoErrors()
        ->assertRedirect("/impor/{$batch->id}/baris");

    return $batch->refresh();
}

/** @return list<ImportRow> */
function procRows(ImportBatch $batch): array
{
    return ImportRow::query()->where('import_batch_id', $batch->id)->orderBy('row_number')->get()->all();
}

test('BR-054 ImportProcess: baris valid → pegawai + penugasan utama; nomor kosong dari penomoran, nomor berkas dipakai; NIK lengkap dibaca ulang dari berkas', function () {
    $nik = procNik();
    $batch = procValidated($this->hrd, [
        procRow('Andi Pratama', ['nik' => $nik, 'lahir' => '17/08/1995', 'hp' => '0812-3456-7890', 'alamat' => 'Jl. Contoh 1']),
        procRow('Sari Wulandari', ['jk' => 'P', 'masuk' => '2024-03-01', 'lokasi' => 'TK-01', 'jabatan' => 'KA-GDG', 'no' => 'IMP-002']),
    ]);
    expect($batch->status)->toBe(ImportStatus::Validated);

    $batch = procRun($this->hrd, $batch);

    expect($batch->status)->toBe(ImportStatus::Completed)
        ->and([$batch->created_rows, $batch->updated_rows, $batch->skipped_rows, $batch->error_rows])->toBe([2, 0, 0, 0])
        ->and($batch->completed_at)->not->toBeNull()
        ->and(Storage::disk('local')->exists($batch->stored_path))->toBeFalse();

    $andi = Employee::query()->where('full_name', 'Andi Pratama')->firstOrFail();
    $sari = Employee::query()->where('full_name', 'Sari Wulandari')->firstOrFail();
    expect($andi->employee_number)->not->toBe('')->not->toBe('IMP-002')
        ->and(app(NikCipher::class)->decrypt((string) $andi->nik_enc))->toBe($nik)
        ->and($andi->birth_date?->toDateString())->toBe('1995-08-17')
        ->and($andi->primaryAssignment?->location_id)->toBe($this->world->gudang->id)
        ->and($andi->primaryAssignment?->start_date->toDateString())->toBe('2024-02-01')
        ->and($sari->employee_number)->toBe('IMP-002')
        ->and($sari->primaryAssignment?->position_id)->toBe($this->world->head->id);

    $rows = procRows($batch);
    expect($rows[0]->status)->toBe(ImportRowStatus::Created)
        ->and($rows[0]->data['employee_id'])->toBe($andi->id)
        ->and($rows[0]->data['nik'])->not->toBe($nik)->toContain('*');

    // Audit: tiap pegawai tertelusur ke batchnya; satu ringkasan import.processed.
    expect(AuditLog::query()->where('event', 'employee.created')->where('actor_label', "import#{$batch->id}")->count())->toBe(2)
        ->and(AuditLog::query()->where('event', 'import.processed')->where('auditable_id', $batch->id)->first()?->new_values)
        ->toMatchArray(['status' => 'completed', 'created' => 2, 'update_existing' => false]);
});

test('BR-054 ImportProcess: berkas sama diproses 2× → tanpa duplikat; opsi "perbarui" mati → skipped', function () {
    $rows = [procRow('Andi Pratama', ['nik' => procNik()]), procRow('Budi Santoso', ['no' => 'IMP-010']), procRow('Citra Lestari', ['lahir' => '1990-01-15'])];
    procRun($this->hrd, procValidated($this->hrd, $rows));
    expect(Employee::query()->count())->toBe(3);

    $again = procRun($this->hrd, procValidated($this->hrd, $rows));

    expect(Employee::query()->count())->toBe(3)
        ->and([$again->created_rows, $again->skipped_rows])->toBe([0, 3])
        ->and(array_map(fn (ImportRow $r) => $r->status, procRows($again)))->each->toBe(ImportRowStatus::Skipped);
});

test('BR-054 ImportProcess: opsi "perbarui" → hanya field terpetakan yang tidak kosong; beda lokasi/jabatan hanya peringatan', function () {
    $old = $this->world->employee(attributes: ['employee_number' => 'LAMA-01', 'full_name' => 'Nama Lama', 'phone' => '081200000001']);
    $assignment = $old->primaryAssignment;

    $batch = procRun($this->hrd, procValidated($this->hrd, [
        procRow('Nama Baru', ['no' => 'LAMA-01', 'lokasi' => 'TK-01', 'jabatan' => 'KA-GDG', 'alamat' => 'Jl. Baru 2']),
    ]), update: true);

    $old->refresh();
    $row = procRows($batch)[0];
    expect($row->status)->toBe(ImportRowStatus::Updated)
        ->and($old->full_name)->toBe('Nama Baru')
        ->and($old->address)->toBe('Jl. Baru 2')
        ->and($old->phone)->toBe('081200000001') // sel HP kosong tidak menghapus
        ->and($old->primaryAssignment?->id)->toBe($assignment?->id)
        ->and($old->primaryAssignment?->location_id)->toBe($this->world->gudang->id)
        ->and(implode(' ', $row->data['warnings']))->toContain('Lokasi di berkas')->toContain('Jabatan di berkas');

    // Data sama diproses lagi dengan opsi nyala → tidak ada perubahan → skipped, tanpa audit kosong.
    $audits = AuditLog::query()->where('event', 'employee.updated')->count();
    $same = procRun($this->hrd, procValidated($this->hrd, [
        procRow('Nama Baru', ['no' => 'LAMA-01', 'alamat' => 'Jl. Baru 2']),
    ]), update: true);
    expect(procRows($same)[0]->status)->toBe(ImportRowStatus::Skipped)
        ->and(AuditLog::query()->where('event', 'employee.updated')->count())->toBe($audits);

    // Opsi mati → skipped dan data tidak berubah.
    procRun($this->hrd, procValidated($this->hrd, [procRow('Nama Lain', ['no' => 'LAMA-01'])]));
    expect($old->refresh()->full_name)->toBe('Nama Baru');
});

test('BR-054 ImportProcess: atasan dari berkas yang sama dibuat lebih dulu walau barisnya di bawah; atasan melingkar → galat', function () {
    $batch = procRun($this->hrd, procValidated($this->hrd, [
        procRow('Staf Baru', ['no' => 'IMP-B', 'atasan' => 'IMP-A']),
        procRow('Kepala Baru', ['no' => 'IMP-A', 'jabatan' => 'KA-GDG']),
        procRow('Lingkar Satu', ['no' => 'IMP-C', 'atasan' => 'IMP-D']),
        procRow('Lingkar Dua', ['no' => 'IMP-D', 'atasan' => 'IMP-C']),
        procRow('Ikut Lingkar', ['no' => 'IMP-E', 'atasan' => 'IMP-C']),
    ]));

    $head = Employee::query()->where('employee_number', 'IMP-A')->firstOrFail();
    $staff = Employee::query()->where('employee_number', 'IMP-B')->firstOrFail();
    $rows = procRows($batch);

    expect($staff->primaryAssignment?->supervisor_employee_id)->toBe($head->id)
        ->and([$rows[0]->status, $rows[1]->status])->toBe([ImportRowStatus::Created, ImportRowStatus::Created])
        ->and([$rows[2]->status, $rows[3]->status, $rows[4]->status])->each->toBe(ImportRowStatus::Invalid)
        ->and($rows[2]->errors['supervisor_number'][0])->toContain('melingkar')
        ->and($rows[4]->errors['supervisor_number'][0])->toContain('tidak berhasil diproses')
        ->and(Employee::query()->whereIn('employee_number', ['IMP-C', 'IMP-D', 'IMP-E'])->exists())->toBeFalse()
        ->and($batch->error_rows)->toBe(3);
});

test('T-028 ImportProcess: gagal di tengah → batch failed, baris yang sudah sukses tetap konsisten (transaksi per baris)', function () {
    Notification::fake();
    Employee::creating(function (Employee $e) {
        if ($e->full_name === 'Gagal Tiga') {
            throw new RuntimeException('Simulasi DB putus (test).');
        }
    });

    $batch = procRun($this->hrd, procValidated($this->hrd, [
        procRow('Satu Pertama'), procRow('Dua Kedua'), procRow('Gagal Tiga'), procRow('Empat Keempat'),
    ]));

    $rows = procRows($batch);
    expect($batch->status)->toBe(ImportStatus::Failed)
        ->and(array_map(fn (ImportRow $r) => $r->status, $rows))->toBe([ImportRowStatus::Created, ImportRowStatus::Created, ImportRowStatus::Valid, ImportRowStatus::Valid])
        ->and(Employee::query()->pluck('full_name')->sort()->values()->all())->toBe(['Dua Kedua', 'Satu Pertama'])
        ->and(Employee::query()->has('assignments')->count())->toBe(2)
        ->and($batch->created_rows)->toBe(2)
        ->and(Storage::disk('local')->exists($batch->stored_path))->toBeFalse();

    Notification::assertSentTo($this->hrd, ImportDoneNotification::class, fn (ImportDoneNotification $n) => $n->title() === 'Impor pegawai gagal');
});

test('T-028 ImportProcess: validasi ulang saat proses — lokasi dinonaktifkan sesudah validasi → baris galat, sisanya jalan', function () {
    $batch = procValidated($this->hrd, [procRow('Andi Pratama'), procRow('Sari Wulandari', ['lokasi' => 'TK-01'])]);
    expect($batch->valid_rows)->toBe(2);

    $this->world->toko->forceFill(['is_active' => false])->save();
    $batch = procRun($this->hrd, $batch);

    $rows = procRows($batch);
    expect([$rows[0]->status, $rows[1]->status])->toBe([ImportRowStatus::Created, ImportRowStatus::Invalid])
        ->and($rows[1]->errors['location'][0])->toContain('tidak ditemukan')
        ->and([$batch->created_rows, $batch->error_rows])->toBe([1, 1]);
});

test('06 §7.4 ImportProcess: proses hanya dari validated; proses ulang & batal setelah diproses ditolak', function () {
    $batch = procRun($this->hrd, procValidated($this->hrd, [procRow('Andi Pratama')]));

    $this->actingAs($this->hrd)->post("/impor/{$batch->id}/proses")->assertSessionHasErrors('status');
    $this->actingAs($this->hrd)->post("/impor/{$batch->id}/batal")->assertSessionHasErrors('status');
    expect(Employee::query()->count())->toBe(1);

    // Semua baris galat → tidak ada yang bisa diproses.
    $empty = procValidated($this->hrd, [procRow('Andi Salah', ['lokasi' => 'XX-99'])]);
    $this->actingAs($this->hrd)->post("/impor/{$empty->id}/proses")->assertSessionHasErrors('status');
    expect($empty->refresh()->status)->toBe(ImportStatus::Validated);

    $this->actingAs($this->hrd)->get("/impor/{$batch->id}/baris")->assertInertia(fn (Assert $page) => $page
        ->component('imports/rows')
        ->where('batch.status', 'completed')
        ->where('batch.created_rows', 1)
        ->where('can.process', false)
        ->where('can.download_errors', false));
});

test('T-028 ImportProcess: > 200 baris valid → antrean `imports`; job selesai → notifikasi ke pengunggah + audit', function () {
    Queue::fake();
    Notification::fake();
    $lines = '';
    for ($i = 1; $i <= 201; $i++) {
        $lines .= sprintf("Pegawai Antrean %03d;L;01/02/2024;Tetap;GD-01;STF\n", $i);
    }
    $batch = impUpload($this->hrd, impCsv("Nama;JK;Tgl Masuk;Status;Lokasi;Jabatan\n".$lines));
    impMapSuggested($this->hrd, $batch);
    (new ValidateImportBatchJob($batch->id, $this->hrd->id))->handle(app(ValidateImportBatch::class));
    expect($batch->refresh()->valid_rows)->toBe(201);

    $batch = procRun($this->hrd, $batch, update: true);
    expect($batch->status)->toBe(ImportStatus::Processing);
    Queue::assertPushedOn('imports', ProcessImportBatchJob::class, fn (ProcessImportBatchJob $job) => $job->batchId === $batch->id);

    (new ProcessImportBatchJob($batch->id, $this->hrd->id))->handle(app(ProcessImportBatch::class));

    expect($batch->refresh()->status)->toBe(ImportStatus::Completed)
        ->and($batch->created_rows)->toBe(201)
        ->and(AuditLog::query()->where('event', 'employee.created')->where('actor_label', "import#{$batch->id}")->where('user_id', $this->hrd->id)->count())->toBe(201)
        ->and(AuditLog::query()->where('event', 'import.processed')->where('auditable_id', $batch->id)->value('new_values'))->toMatchArray(['update_existing' => true]);
    Notification::assertSentTo($this->hrd, ImportDoneNotification::class, fn (ImportDoneNotification $n) => $n->title() === 'Impor pegawai selesai'
        && str_contains($n->body(), '201 dibuat') && $n->url() === "/impor/{$batch->id}/baris");

    // Job yang terlambat (batch sudah selesai) tidak memproses ulang.
    (new ProcessImportBatchJob($batch->id, $this->hrd->id))->handle(app(ProcessImportBatch::class));
    expect(Employee::query()->count())->toBe(201);
});

test('T-028 ImportProcess: job mati di tengah → batch tidak tertahan `processing`', function () {
    Queue::fake();
    $batch = procValidated($this->hrd, [procRow('Andi Pratama')]);
    $batch->forceFill(['status' => ImportStatus::Processing])->save();

    (new ProcessImportBatchJob($batch->id, $this->hrd->id))->failed(new RuntimeException('timeout (test)'));

    expect($batch->refresh()->status)->toBe(ImportStatus::Failed)
        ->and(Storage::disk('local')->exists($batch->stored_path))->toBeFalse();
});

test('SEC-051 ImportProcess: unduh galat .xlsx — baris galat + kolom pesan, NIK termasking, formula jadi teks', function () {
    $nik = procNik();
    // CSV: di xlsx buatan OpenSpout, teks berawalan "=" ditulis sebagai sel formula, bukan teks.
    $batch = impUpload($this->hrd, impCsv(implode("\n", array_map(fn (array $r) => implode(';', $r), [
        PROC_HEADER,
        procRow('Andi Pratama'),
        procRow('Sari Salah', ['lokasi' => 'XX-99', 'nik' => $nik, 'alamat' => '=HYPERLINK(contoh.test)']),
    ]))."\n"));
    impMapSuggested($this->hrd, $batch);

    $download = function () use ($batch): array {
        $response = $this->actingAs($this->hrd)->get("/impor/{$batch->id}/galat")->assertOk();
        $rows = iterator_to_array((new SpreadsheetReader)->rows($response->baseResponse->getFile()->getPathname(), 'xlsx'), false);

        return $rows;
    };

    // Sebelum diproses (perbaiki berkas) maupun sesudahnya (laporan), berkas asli sudah dihapus.
    foreach ([false, true] as $processed) {
        if ($processed) {
            procRun($this->hrd, $batch);
        }
        $rows = $download();
        $header = $rows[0];
        $line = array_combine($header, $rows[1]);

        expect($rows)->toHaveCount(2)
            ->and($header[0])->toBe('Baris')->and(end($header))->toBe('Pesan galat')
            ->and($line['Nama'])->toBe('Sari Salah')
            ->and((string) $line['NIK'])->not->toContain($nik)->toContain('*')
            ->and($line['Alamat'])->toStartWith("'=HYPERLINK")
            ->and($line['Pesan galat'])->toContain('Lokasi:');
    }

    expect(AuditLog::query()->where('event', 'export.created')->where('auditable_id', $batch->id)->count())->toBe(2);
});

test('SEC-033 ImportProcess: batch orang lain → 404; pegawai cocok di luar scope tidak diubah; kepala lokasi/supervisor ditolak', function () {
    $self = $this->world->employee($this->world->gudang);
    $role = Role::query()->create(['name' => 'hrd_lokasi', 'label' => 'HRD Lokasi', 'guard_name' => 'web', 'data_scope' => DataScope::Location]);
    $role->givePermissionTo(['employees.view', 'employees.update', 'employees.import']);
    $local = User::factory()->withTwoFactor()->create(['employee_id' => $self->id])->assignRole('hrd_lokasi');

    $others = procValidated($this->hrd, [procRow('Sari Salah', ['lokasi' => 'XX-99'])]);
    $this->assertOutOfScope404($local, 'POST', "/impor/{$others->id}/proses");
    $this->assertOutOfScope404($local, 'GET', "/impor/{$others->id}/galat");

    $outside = $this->world->employee($this->world->toko, attributes: ['employee_number' => 'TK-LAMA', 'full_name' => 'Pegawai Toko']);
    $own = procRun($local, procValidated($local, [procRow('Nama Diubah', ['no' => 'TK-LAMA'])]), update: true);

    expect(procRows($own)[0]->status)->toBe(ImportRowStatus::Invalid)
        ->and(procRows($own)[0]->errors['_row'][0])->toContain('tidak bisa Anda ubah')
        ->and($outside->refresh()->full_name)->toBe('Pegawai Toko');

    foreach (['kepala_lokasi', 'supervisor'] as $name) {
        $actor = $this->world->actor($name, $this->world->employee());
        expect($this->actingAs($actor)->post("/impor/{$others->id}/proses")->status())->toBeIn([403, 404])
            ->and($this->actingAs($actor)->get("/impor/{$others->id}/galat")->status())->toBeIn([403, 404]);
    }
    expect($others->refresh()->status)->toBe(ImportStatus::Validated);
});

test('E2E-08 ImportProcess: 30 baris (5 galat) → perbaiki pemetaan → proses → 25 dibuat; impor ulang → 0 dibuat, 25 dilewati', function () {
    $rows = [['Nama', 'JK', 'Tgl Masuk', 'Status', 'Lokasi', 'Jabatan', 'Tgl Lahir', 'Kontak']];
    for ($i = 1; $i <= 30; $i++) {
        $rows[] = [sprintf('Pegawai Uji %02d', $i), $i % 2 ? 'L' : 'P', '01/02/2024', 'Tetap', $i > 25 ? 'XX-99' : 'GD-01', 'STF', '15/01/1990', sprintf('0812-0000-%04d', $i)];
    }
    $file = fn () => impXlsx($rows);

    // Pemetaan pertama salah: kolom "Kontak" (nomor HP) dipetakan ke email → semua baris galat.
    $batch = impUpload($this->hrd, $file());
    $columns = MappingSuggester::suggest(ImportRegistry::forBatch($batch), $batch->headers())['columns'];
    $columns[7] = 'email';
    $this->actingAs($this->hrd)->put("/impor/{$batch->id}/pemetaan", ['columns' => $columns])->assertSessionHasNoErrors();
    expect($batch->refresh()->error_rows)->toBe(30);

    // Diperbaiki: "Kontak" → phone. Tersisa 5 galat (lokasi tidak dikenal).
    $columns[7] = 'phone';
    $this->actingAs($this->hrd)->put("/impor/{$batch->id}/pemetaan", ['columns' => $columns])->assertSessionHasNoErrors();
    expect([$batch->refresh()->valid_rows, $batch->error_rows])->toBe([25, 5]);

    $batch = procRun($this->hrd, $batch);
    expect([$batch->created_rows, $batch->error_rows])->toBe([25, 5])
        ->and(Employee::query()->count())->toBe(25);

    $again = impUpload($this->hrd, $file());
    $this->actingAs($this->hrd)->put("/impor/{$again->id}/pemetaan", ['columns' => $columns])->assertSessionHasNoErrors();
    $again = procRun($this->hrd, $again->refresh());

    expect([$again->created_rows, $again->skipped_rows, $again->error_rows])->toBe([0, 25, 5])
        ->and(Employee::query()->count())->toBe(25);
});

test('06 §4 ImportProcess: role kustom dengan employees.import saja tidak bisa membuat/mengubah pegawai lewat impor', function () {
    $role = Role::query()->create(['name' => 'importir_saja', 'label' => 'Importir saja', 'guard_name' => 'web', 'data_scope' => DataScope::All]);
    $role->givePermissionTo(['employees.view', 'employees.import']);
    $importer = User::factory()->withTwoFactor()->create()->assignRole('importir_saja');
    $old = $this->world->employee(attributes: ['employee_number' => 'LAMA-09', 'full_name' => 'Nama Lama']);

    // Opsi "perbarui" ditolak sejak awal; batch tetap bisa diproses ulang tanpa opsi.
    $batch = procValidated($importer, [procRow('Nama Diubah', ['no' => 'LAMA-09']), procRow('Pegawai Baru Ilegal')]);
    $this->actingAs($importer)->post("/impor/{$batch->id}/proses", ['update_existing' => true])->assertSessionHasErrors('status');
    $this->actingAs($importer)->get("/impor/{$batch->id}/baris")->assertInertia(fn (Assert $page) => $page->where('can.update_existing', false));

    $batch = procRun($importer, $batch->refresh());
    $rows = procRows($batch);
    expect($rows[0]->status)->toBe(ImportRowStatus::Skipped)
        ->and($rows[1]->status)->toBe(ImportRowStatus::Invalid)
        ->and($rows[1]->errors['_row'][0])->toContain('tidak punya izin menambah')
        ->and($old->refresh()->full_name)->toBe('Nama Lama')
        ->and(Employee::query()->where('full_name', 'Pegawai Baru Ilegal')->exists())->toBeFalse();

    // Izin dicabut setelah proses dimulai (antrean): dicek ulang per baris.
    $role->givePermissionTo('employees.update');
    $importer->refresh();
    $queued = procValidated($importer, [procRow('Nama Diubah', ['no' => 'LAMA-09'])]);
    $queued->forceFill(['status' => ImportStatus::Processing, 'mapping' => [...$queued->mapping, 'options' => ['update_existing' => true]]])->save();
    $role->revokePermissionTo('employees.update');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new ProcessImportBatchJob($queued->id, $importer->id))->handle(app(ProcessImportBatch::class));
    expect(procRows($queued->refresh())[0]->errors['_row'][0])->toContain('tidak punya izin mengubah')
        ->and($old->refresh()->full_name)->toBe('Nama Lama');
});

test('T-028 ImportProcess: job antrean melepas identitas pengunggah setelah selesai (worker dipakai ulang)', function () {
    Queue::fake();
    $batch = procValidated($this->hrd, [procRow('Andi Pratama')]);
    $batch->forceFill(['status' => ImportStatus::Processing])->save();
    auth()->forgetGuards();

    (new ProcessImportBatchJob($batch->id, $this->hrd->id))->handle(app(ProcessImportBatch::class));

    expect($batch->refresh()->status)->toBe(ImportStatus::Completed)
        ->and(AuditLog::query()->where('event', 'employee.created')->value('user_id'))->toBe($this->hrd->id)
        ->and(auth()->guard()->user())->toBeNull();
});
