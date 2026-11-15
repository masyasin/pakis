<?php

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Models\Role;
use App\Domain\Employee\Actions\ValidateImportBatch;
use App\Domain\Employee\Enums\ImportRowStatus;
use App\Domain\Employee\Enums\ImportStatus;
use App\Domain\Employee\Import\EmployeeImportReader;
use App\Domain\Employee\Import\HeaderDetector;
use App\Domain\Employee\Import\MappingSuggester;
use App\Domain\Employee\Models\ImportBatch;
use App\Domain\Employee\Models\ImportMappingProfile;
use App\Domain\Employee\Models\ImportRow;
use App\Jobs\ValidateImportBatchJob;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Support\AssertsIdor;
use Tests\Support\EmployeeWorld;

uses(AssertsIdor::class);

beforeEach(function () {
    Storage::fake('local');
    $this->world = EmployeeWorld::make($this);
    $this->hrd = $this->world->actor('hrd');
    // NIK uji acak (bukan NIK asli), dibuat saat test berjalan.
    $this->nik = '3319'.str_pad((string) random_int(0, 999_999_999_999), 12, '0', STR_PAD_LEFT);
});

/** @param  list<list<mixed>>  $rows */
function impXlsx(array $rows, string $name = 'pegawai.xlsx'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function impCsv(string $content, string $name = 'pegawai.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

/** Unggah lalu kembalikan batch terakhir. */
function impUpload(User $actor, UploadedFile $file): ImportBatch
{
    test()->actingAs($actor)->post('/impor/pegawai', ['file' => $file])->assertSessionHasNoErrors();

    return ImportBatch::query()->latest('id')->firstOrFail();
}

/** Simpan pemetaan hasil saran apa adanya. */
function impMapSuggested(User $actor, ImportBatch $batch, array $extra = []): void
{
    $columns = MappingSuggester::suggest($batch->headers())['columns'];
    test()->actingAs($actor)->put("/impor/{$batch->id}/pemetaan", ['columns' => $columns, ...$extra])
        ->assertSessionHasNoErrors()
        ->assertRedirect("/impor/{$batch->id}/baris");
}

/** Excel "berantakan" (data dummy): judul + baris kosong, header di baris 3, kolom ekstra, format campur. */
function impMessy(string $nik): UploadedFile
{
    $file = impXlsx([
        ['DAFTAR PEGAWAI CV CONTOH (DATA DUMMY)'],
        [''],
        ['No', 'Nama Lengkap', 'JK', 'Tgl Masuk', 'Status', 'Lokasi', 'Jabatan', 'No HP', 'NIK', 'Tanggal Lahir', 'Keterangan'],
        [1, 'Andi  Pratama', 'L', '01/02/2024', 'Tetap', 'GD-01', 'STF', '0812-3456-7890', $nik, '17 Agustus 1995', 'pindahan'],
        [2, 'Sari Wulandari', 'Perempuan', new DateTimeImmutable('2023-05-10'), 'permanent', 'Toko Uji', 'Staf Gudang', 81234567891, '', 34000, ''],
        [3, 'Budi Santoso', 'Pria', '2024-13-01', 'Kontrak Lepas', 'XX-99', 'STF', 'abc', 3319010101010001, '', ''],
        [''],
        [4, 'Andi Pratama Dobel', 'L', '3 Nov 2025', 'Tetap', 'GD-01', 'STF', '', $nik, '', ''],
        [5, '=1+1', 'P', '2025-01-02', 'Tetap', 'GD-01', 'STF', '', '', '', ''],
    ]);

    // Excel menyimpan hasil formula di <v>; writer OpenSpout tidak. Sisipkan nilai tersimpan agar
    // test membuktikan yang dibaca adalah nilai itu, bukan hasil evaluasi formula (BR-054).
    $zip = new ZipArchive;
    $zip->open($file->getRealPath());
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $patched = (string) preg_replace('/<c r="B9"[^>]*><f>1\+1<\/f><\/c>/', '<c r="B9" t="str"><f>1+1</f><v>Rina Tersimpan</v></c>', $sheet, 1, $count);
    expect($count)->toBe(1);
    $zip->addFromString('xl/worksheets/sheet1.xml', $patched);
    $zip->close();

    return $file;
}

test('BR-054 fixture berantakan terbaca benar: header baris 3, tanggal campur, HP 0812-xxx, kolom ekstra, NIK termasking', function () {
    $batch = impUpload($this->hrd, impMessy($this->nik));

    expect($batch->status)->toBe(ImportStatus::Uploaded)
        ->and($batch->headerRow())->toBe(3)
        ->and($batch->total_rows)->toBe(5);

    $this->actingAs($this->hrd)->get("/impor/{$batch->id}/pemetaan")->assertInertia(fn (Assert $page) => $page
        ->component('imports/mapping')
        ->where('mapping', ['1' => 'full_name', '2' => 'gender', '3' => 'join_date', '4' => 'employment_status', '5' => 'location', '6' => 'position', '7' => 'phone', '8' => 'nik', '9' => 'birth_date'])
        ->where('profile', null)
        // Contoh nilai: deretan 16 digit dimasking sebelum NIK dipetakan.
        ->where('samples.8.0', substr($this->nik, 0, 4).'********'.substr($this->nik, -4)));

    impMapSuggested($this->hrd, $batch);
    $batch->refresh();

    expect($batch->status)->toBe(ImportStatus::Validated)
        ->and([$batch->total_rows, $batch->valid_rows, $batch->error_rows])->toBe([5, 3, 2]);

    $rows = ImportRow::query()->where('import_batch_id', $batch->id)->orderBy('row_number')->get()->keyBy('row_number');
    expect($rows->keys()->all())->toBe([4, 5, 6, 8, 9]);

    $andi = $rows[4];
    expect($andi->status)->toBe(ImportRowStatus::Valid)
        ->and($andi->data)->toMatchArray([
            'full_name' => 'Andi Pratama', 'gender' => 'L', 'join_date' => '2024-02-01', 'birth_date' => '1995-08-17',
            'phone' => '6281234567890', 'employment_status' => 'Tetap', 'location' => 'Gudang Uji', 'position' => 'Staf Gudang',
            'nik' => substr($this->nik, 0, 4).'********'.substr($this->nik, -4),
        ])
        ->and($andi->data['ids'])->toBe(['employment_status_id' => $this->world->permanent, 'location_id' => $this->world->gudang->id, 'position_id' => $this->world->staff->id])
        ->and($andi->row_key)->toStartWith('nik:');

    expect($rows[5]->status)->toBe(ImportRowStatus::Valid)
        ->and($rows[5]->data)->toMatchArray(['gender' => 'P', 'join_date' => '2023-05-10', 'birth_date' => '1993-01-31', 'phone' => '6281234567891', 'location' => 'Toko Uji']);

    expect($rows[6]->status)->toBe(ImportRowStatus::Invalid)
        ->and(array_keys($rows[6]->errors))->toEqualCanonicalizing(['join_date', 'employment_status', 'location', 'phone', 'nik'])
        ->and($rows[6]->data['nik'])->toBeNull();

    // NIK sama dengan baris 4 → duplikat dalam berkas; sel formula = nilai tersimpannya, bukan "2".
    expect($rows[8]->errors['_row'][0])->toContain('Duplikat dengan baris 4')
        ->and($rows[9]->status)->toBe(ImportRowStatus::Valid)
        ->and($rows[9]->data['full_name'])->toBe('Rina Tersimpan');

    // SEC-010: tidak ada NIK 16 digit utuh di import_rows.
    $dump = DB::table('import_rows')->get()->map(fn ($r) => $r->data.$r->errors.$r->row_key)->implode('|');
    expect($dump)->not->toContain($this->nik)->not->toContain('3319010101010001');

    $this->actingAs($this->hrd)->get("/impor/{$batch->id}/baris")->assertInertia(fn (Assert $page) => $page
        ->component('imports/rows')
        ->where('listing.state.filters.status', [ImportRowStatus::Invalid->value])
        ->has('rows', 2));
});

test('SEC-050 berkas .php diganti nama .xlsx / CSV berisi tag PHP / ekstensi lain → ditolak', function () {
    $php = impCsv('<?php echo "x"; ?>', 'data.xlsx');
    $this->actingAs($this->hrd)->post('/impor/pegawai', ['file' => $php])->assertSessionHasErrors('file');

    $this->actingAs($this->hrd)->post('/impor/pegawai', ['file' => impCsv("Nama;JK\n<?php system('x'); ?>;L\n")])->assertSessionHasErrors('file');
    $this->actingAs($this->hrd)->post('/impor/pegawai', ['file' => impCsv('Nama,JK', 'data.php')])->assertSessionHasErrors('file');

    expect(ImportBatch::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('imports'))->toBe([]);
});

test('BR-054 batas ukuran & jumlah baris dari setting', function () {
    setSetting('import.max_file_mb', 1);
    $this->actingAs($this->hrd)->post('/impor/pegawai', ['file' => UploadedFile::fake()->create('besar.xlsx', 1100)])->assertSessionHasErrors('file');

    setSetting('import.max_rows', 50);
    $csv = "Nama;JK;Tgl Masuk\n".str_repeat("Pegawai Dummy;L;01/02/2024\n", 51);
    $this->actingAs($this->hrd)->post('/impor/pegawai', ['file' => impCsv($csv)])->assertSessionHasErrors('file');

    expect(ImportBatch::query()->count())->toBe(0);
});

test('BR-054 CSV titik koma + BOM + Windows-1252 terbaca', function () {
    $csv = "\xEF\xBB\xBFNama Lengkap;Jenis Kelamin;Tanggal Masuk;Status Kerja;Lokasi;Jabatan\nAndi Pratama;L;01-02-2024;Tetap;GD-01;STF\n";
    $batch = impUpload($this->hrd, impCsv($csv));
    impMapSuggested($this->hrd, $batch);
    expect($batch->refresh()->valid_rows)->toBe(1);

    $latin = mb_convert_encoding("Nama;JK;Tgl Masuk;Status;Lokasi;Jabatan\nJosé Ramírez;L;01/02/2024;Tetap;GD-01;STF\n", 'Windows-1252', 'UTF-8');
    $batch = impUpload($this->hrd, impCsv($latin));
    impMapSuggested($this->hrd, $batch);
    expect(ImportRow::query()->where('import_batch_id', $batch->id)->value('data')['full_name'])->toBe('José Ramírez');
});

test('BR-054 profil pemetaan tersimpan → otomatis terpilih untuk header yang sama', function () {
    $headers = ['Nama Pegawai', 'Kelamin', 'Masuk Kerja', 'Hubungan', 'Unit', 'Posisi'];
    $row = ['Andi Pratama', 'L', '01/02/2024', 'Tetap', 'GD-01', 'STF'];
    $batch = impUpload($this->hrd, impXlsx([$headers, $row]));

    // "Hubungan" & "Unit" bukan sinonim: dipetakan manual lalu disimpan sebagai profil.
    $columns = ['0' => 'full_name', '1' => 'gender', '2' => 'join_date', '3' => 'employment_status', '4' => 'location', '5' => 'position'];
    $this->actingAs($this->hrd)->put("/impor/{$batch->id}/pemetaan", ['columns' => $columns, 'save_profile' => true, 'profile_name' => 'Format HRD'])
        ->assertSessionHasNoErrors();
    expect(ImportMappingProfile::query()->where('name', 'Format HRD')->value('mapping'))->toMatchArray(['Hubungan' => 'employment_status', 'Unit' => 'location']);

    $second = impUpload($this->hrd, impXlsx([array_map('strtoupper', $headers), $row], 'bulan-depan.xlsx'));
    $this->actingAs($this->hrd)->get("/impor/{$second->id}/pemetaan")->assertInertia(fn (Assert $page) => $page
        ->where('profile.name', 'Format HRD')
        ->where('mapping', $columns));
});

test('BR-054 pemetaan: field wajib harus dipetakan, satu field tidak boleh dua kolom', function () {
    $batch = impUpload($this->hrd, impXlsx([['Nama', 'Nama Panggilan', 'JK'], ['Andi', 'Andi', 'L']]));
    $url = "/impor/{$batch->id}/pemetaan";

    $this->actingAs($this->hrd)->put($url, ['columns' => ['0' => 'full_name', '2' => 'gender']])->assertSessionHasErrors('columns');
    $this->actingAs($this->hrd)->put($url, ['columns' => ['0' => 'full_name', '1' => 'full_name']])->assertSessionHasErrors('columns');
    $this->actingAs($this->hrd)->put($url, ['columns' => ['0' => 'gaji']])->assertSessionHasErrors('columns.0');

    expect($batch->refresh()->status)->toBe(ImportStatus::Uploaded);
});

test('BR-054 atasan: dari pegawai aktif atau dari berkas yang sama; tidak dikenal → galat', function () {
    $boss = $this->world->employee();
    $batch = impUpload($this->hrd, impXlsx([
        ['Nama', 'JK', 'Tgl Masuk', 'Status', 'Lokasi', 'Jabatan', 'No Pegawai', 'No Atasan'],
        ['Kepala Baru', 'L', '01/02/2024', 'Tetap', 'GD-01', 'KA-GDG', 'BARU-01', $boss->employee_number],
        ['Staf Baru', 'P', '01/02/2024', 'Tetap', 'GD-01', 'STF', 'BARU-02', 'BARU-01'],
        ['Staf Lain', 'P', '01/02/2024', 'Tetap', 'GD-01', 'STF', 'BARU-03', 'TIDAK-ADA'],
        ['Staf Aneh', 'P', '01/02/2024', 'Tetap', 'GD-01', 'STF', 'BARU-04', 'BARU-04'],
    ]));
    impMapSuggested($this->hrd, $batch);

    $rows = ImportRow::query()->where('import_batch_id', $batch->id)->orderBy('row_number')->get();
    expect($rows->pluck('status')->all())->toBe([ImportRowStatus::Valid, ImportRowStatus::Valid, ImportRowStatus::Invalid, ImportRowStatus::Invalid])
        ->and($rows[0]->data['ids']['supervisor_employee_id'])->toBe($boss->id)
        ->and($rows[1]->data['ids'])->not->toHaveKey('supervisor_employee_id')
        ->and(array_keys($rows[3]->errors))->toBe(['supervisor_number']);
});

test('T-027 berkas > 200 baris divalidasi di antrean `imports`; ≤ 200 langsung', function () {
    Queue::fake();
    $line = "Pegawai Dummy;L;01/02/2024;Tetap;GD-01;STF\n";
    $big = impUpload($this->hrd, impCsv("Nama;JK;Tgl Masuk;Status;Lokasi;Jabatan\n".str_repeat($line, 201)));
    impMapSuggested($this->hrd, $big);

    expect($big->refresh()->status)->toBe(ImportStatus::Mapped);
    Queue::assertPushedOn('imports', ValidateImportBatchJob::class, fn (ValidateImportBatchJob $job) => $job->batchId === $big->id);

    // Job berjalan: status validated; nama sama tanpa NIK/nomor/tgl lahir → duplikat (kunci nama).
    (new ValidateImportBatchJob($big->id, $this->hrd->id))->handle(app(ValidateImportBatch::class));
    expect($big->refresh()->status)->toBe(ImportStatus::Validated)
        ->and([$big->valid_rows, $big->error_rows])->toBe([1, 200]);

    $small = impUpload($this->hrd, impCsv("Nama;JK;Tgl Masuk;Status;Lokasi;Jabatan\n".str_repeat($line, 200)));
    impMapSuggested($this->hrd, $small);
    expect($small->refresh()->status)->toBe(ImportStatus::Validated);
    Queue::assertPushed(ValidateImportBatchJob::class, 1);
});

test('06 §7.4 batal hanya dari uploaded/mapped/validated; berkas dihapus', function () {
    $batch = impUpload($this->hrd, impXlsx([['Nama', 'JK'], ['Andi', 'L'], ['Sari', 'P'], ['Budi', 'L'], ['Dewi', 'P']]));
    expect(Storage::disk('local')->exists($batch->stored_path))->toBeTrue();

    // Halaman pemetaan membaca 3 contoh lalu berhenti: handle berkas harus tetap tertutup (Windows
    // menolak menghapus berkas yang masih terbuka).
    $this->actingAs($this->hrd)->get("/impor/{$batch->id}/pemetaan")->assertOk();
    $this->actingAs($this->hrd)->post("/impor/{$batch->id}/batal")->assertRedirect('/impor');
    expect($batch->refresh()->status)->toBe(ImportStatus::Cancelled)
        ->and(Storage::disk('local')->exists($batch->stored_path))->toBeFalse();

    $this->actingAs($this->hrd)->post("/impor/{$batch->id}/batal")->assertSessionHasErrors('status');
    $this->actingAs($this->hrd)->put("/impor/{$batch->id}/pemetaan", ['columns' => ['0' => 'full_name']])->assertSessionHasErrors('columns');
});

test('06 §4 hanya employees.import (kepala lokasi & supervisor 403)', function (string $role) {
    $actor = $this->world->actor($role, $this->world->employee());

    $this->actingAs($actor)->get('/impor')->assertForbidden();
    $this->actingAs($actor)->get('/impor/pegawai/templat')->assertForbidden();
    $this->actingAs($actor)->post('/impor/pegawai', ['file' => impXlsx([['Nama'], ['x']])])->assertForbidden();
})->with(['kepala_lokasi', 'supervisor']);

test('SEC-033 batch milik orang lain tidak terlihat oleh importir ber-scope lokasi (404); lokasi di luar scope → galat baris', function () {
    $self = $this->world->employee($this->world->gudang);
    $role = Role::query()->create(['name' => 'hrd_lokasi', 'label' => 'HRD Lokasi', 'guard_name' => 'web', 'data_scope' => DataScope::Location]);
    $role->givePermissionTo(['employees.view', 'employees.import']);
    $local = User::factory()->withTwoFactor()->create(['employee_id' => $self->id])->assignRole('hrd_lokasi');

    $others = impUpload($this->hrd, impXlsx([['Nama', 'JK'], ['Andi', 'L']]));
    foreach (['GET' => ["/impor/{$others->id}", "/impor/{$others->id}/pemetaan", "/impor/{$others->id}/baris"], 'POST' => ["/impor/{$others->id}/batal"]] as $method => $urls) {
        foreach ($urls as $url) {
            $this->assertOutOfScope404($local, $method, $url);
        }
    }
    $this->actingAs($local)->get('/impor')->assertInertia(fn (Assert $page) => $page->has('rows', 0));

    $own = impUpload($local, impXlsx([
        ['Nama', 'JK', 'Tgl Masuk', 'Status', 'Lokasi', 'Jabatan'],
        ['Andi Pratama', 'L', '01/02/2024', 'Tetap', 'GD-01', 'STF'],
        ['Sari Wulandari', 'P', '01/02/2024', 'Tetap', 'TK-01', 'STF'],
    ]));
    impMapSuggested($local, $own);
    $rows = ImportRow::query()->where('import_batch_id', $own->id)->orderBy('row_number')->get();
    expect($rows[0]->status)->toBe(ImportRowStatus::Valid)
        ->and($rows[1]->errors['location'][0])->toContain('tidak ditemukan');
    $this->actingAs($local)->get('/impor')->assertInertia(fn (Assert $page) => $page->has('rows', 1));
});

test('T-027 templat: xlsx dengan semua field, terbaca kembali sebagai pemetaan lengkap', function () {
    $response = $this->actingAs($this->hrd)->get('/impor/pegawai/templat')->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();

    $top = [];
    foreach ((new EmployeeImportReader)->rows($path, 'xlsx') as $number => $cells) {
        $top[$number] = $cells;
        if ($number >= 2) {
            break;
        }
    }
    $detected = HeaderDetector::detect($top);

    expect($detected['row'])->toBe(1)
        ->and(MappingSuggester::suggest($detected['headers'])['columns'])->toHaveCount(13);
});

test('T-027 profil: daftar & hapus', function () {
    $profile = (new ImportMappingProfile)->forceFill([
        'type' => ImportBatch::TYPE_EMPLOYEES, 'name' => 'Format Lama', 'header_signature' => str_repeat('a', 64),
        'mapping' => ['Nama' => 'full_name'], 'created_by' => $this->hrd->id,
    ]);
    $profile->save();

    $this->actingAs($this->hrd)->get('/impor/profil')->assertInertia(fn (Assert $page) => $page
        ->component('import-profiles/index')->where('rows.0.name', 'Format Lama')->where('rows.0.columns', 1));
    $this->actingAs($this->hrd)->delete("/impor/profil/{$profile->id}")->assertRedirect('/impor/profil');

    expect(ImportMappingProfile::query()->count())->toBe(0);
});

test('SEC-010 contoh nilai di pemetaan: NIK bersela spasi/titik/strip ikut dimasking, HP tidak', function () {
    $batch = impUpload($this->hrd, impXlsx([
        ['Nama', 'No KTP', 'No HP', 'Catatan'],
        ['Andi', '3319 0101.0101-0001', '0812-3456-7890', 'KTP lama 3319010101010002 hilang'],
    ]));

    $this->actingAs($this->hrd)->get("/impor/{$batch->id}/pemetaan")->assertInertia(fn (Assert $page) => $page
        ->where('samples.1.0', '3319********0001')
        ->where('samples.2.0', '0812-3456-7890')
        ->where('samples.3.0', 'KTP lama 3319********0002 hilang'));
});

test('SEC-033 templat: contoh lokasi diambil dari lokasi dalam scope pengunduh', function () {
    $self = $this->world->employee($this->world->toko);
    $role = Role::query()->create(['name' => 'hrd_toko', 'label' => 'HRD Toko', 'guard_name' => 'web', 'data_scope' => DataScope::Location]);
    $role->givePermissionTo(['employees.view', 'employees.import']);
    $local = User::factory()->withTwoFactor()->create(['employee_id' => $self->id])->assignRole('hrd_toko');

    $path = $this->actingAs($local)->get('/impor/pegawai/templat')->assertOk()->baseResponse->getFile()->getPathname();
    $example = null;
    foreach ((new EmployeeImportReader)->rows($path, 'xlsx') as $number => $cells) {
        if ($number === 2) {
            $example = $cells;
            break;
        }
    }

    // GD-01 (di luar scope) lebih dulu menurut kode; contoh harus TK-01.
    expect($example[4])->toBe('TK-01');
});
