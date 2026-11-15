<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Enums\Gender;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\NikCipher;
use App\Domain\Organization\Enums\AttendanceMode;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EmployeeWorld;

beforeEach(function () {
    $this->world = EmployeeWorld::make($this);
    $this->hrd = $this->world->actor('hrd');
    Storage::fake('local');
});

test('FR-025 tambah pegawai + penugasan pertama dalam satu simpan, tercatat audit', function () {
    $response = $this->actingAs($this->hrd)->postJson('/pegawai', $this->world->payload())
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private');

    $employee = Employee::query()->sole();
    expect($response->json('id'))->toBe($employee->id)
        ->and($employee->status)->toBe(EmployeeStatus::Active)
        ->and($employee->gender)->toBe(Gender::Male)
        ->and($employee->employee_number)->toStartWith('PJA-');

    $assignment = Assignment::query()->sole();
    expect($assignment->employee_id)->toBe($employee->id)
        ->and($assignment->is_primary)->toBeTrue()
        ->and($assignment->start_date->toDateString())->toBe('2026-01-05')
        ->and($assignment->attendance_mode)->toBeNull()
        ->and($assignment->effectiveAttendanceMode())->toBe(AttendanceMode::Location);

    expect(AuditLog::query()->where('event', 'employee.created')->where('auditable_id', $employee->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'assignment.created')->where('auditable_id', $assignment->id)->exists())->toBeTrue();
});

test('BR-050 nama dirapikan, HP dinormalisasi ke 62…, email huruf kecil', function () {
    $this->actingAs($this->hrd)->postJson('/pegawai', $this->world->payload())->assertCreated();

    $employee = Employee::query()->sole();
    expect($employee->full_name)->toBe('Budi Santoso')
        ->and($employee->phone)->toBe('6281234567890')
        ->and($employee->email)->toBe('budi@contoh.test');
});

test('BR-050 HP: format yang diterima & yang ditolak', function (string $phone, ?string $expected) {
    $response = $this->actingAs($this->hrd)->postJson('/pegawai', $this->world->payload(['phone' => $phone]));

    if ($expected === null) {
        $response->assertUnprocessable()->assertJsonValidationErrors('phone');

        return;
    }

    $response->assertCreated();
    expect(Employee::query()->sole()->phone)->toBe($expected);
})->with([
    '08…' => ['0812 3456 7890', '6281234567890'],
    '+62…' => ['+62 812-3456-7890', '6281234567890'],
    '62…' => ['6281234567890', '6281234567890'],
    'terlalu pendek' => ['0812', null],
    'huruf' => ['0812-ABCD-123', null],
    'negara lain' => ['+60 12 345 6789', null],
]);

test('BR-050 umur pada tanggal masuk 15–80 tahun', function (string $birth, bool $ok) {
    $response = $this->actingAs($this->hrd)->postJson('/pegawai', $this->world->payload(['birth_date' => $birth, 'join_date' => '2026-01-05']));

    $ok ? $response->assertCreated() : $response->assertUnprocessable()->assertJsonValidationErrors(['birth_date' => 'Umur pada tanggal masuk']);
})->with([
    'tepat 15' => ['2011-01-05', true],
    '14 tahun' => ['2011-01-06', false],
    'tepat 80' => ['1946-01-05', true],
    '81 tahun' => ['1945-01-05', false],
]);

test('07 §7 NIK harus 16 digit: pesan menyebut jumlah digit sekarang', function () {
    $this->actingAs($this->hrd)->postJson('/pegawai', $this->world->payload(['nik' => substr(EmployeeWorld::nik(), 0, 15)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nik' => 'NIK harus 16 digit angka (sekarang 15).']);

    $this->actingAs($this->hrd)->postJson('/pegawai', $this->world->payload(['nik' => '33a9'.str_repeat('1', 12)]))
        ->assertUnprocessable()->assertJsonValidationErrors('nik');
});

test('BR-050 NIK duplikat → "NIK sudah terdaftar atas pegawai lain" tanpa menyebut siapa', function () {
    $nik = EmployeeWorld::nik();
    $other = Employee::factory()->withNik($nik)->create(['full_name' => 'Pemilik NIK Lama']);
    // Pegawai terhapus tetap memegang NIK-nya (index unik mencakup baris terhapus).
    $other->delete();

    $response = $this->actingAs($this->hrd)->postJson('/pegawai', $this->world->payload(['nik' => $nik]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nik' => 'NIK sudah terdaftar atas pegawai lain.']);

    expect($response->getContent())->not->toContain('Pemilik NIK Lama')
        ->and($response->getContent())->not->toContain($other->employee_number)
        ->and(Employee::query()->count())->toBe(0);
});

test('SEC-034 employee_number, status, nik_hash, created_by dari request diabaikan', function () {
    $this->actingAs($this->hrd)->postJson('/pegawai', $this->world->payload([
        'employee_number' => 'PALSU-001',
        'status' => 'inactive',
        'nik_hash' => str_repeat('a', 64),
        'created_by' => 999,
    ]))->assertCreated();

    $employee = Employee::query()->sole();
    expect($employee->employee_number)->not->toBe('PALSU-001')
        ->and($employee->status)->toBe(EmployeeStatus::Active)
        ->and($employee->nik_hash)->toBeNull()
        ->and($employee->created_by)->toBe($this->hrd->id);

    $this->actingAs($this->hrd)->put("/pegawai/{$employee->id}", [
        ...$this->world->payload(), 'employee_number' => 'PALSU-002', 'status' => 'inactive',
    ])->assertRedirect();

    $employee->refresh();
    expect($employee->employee_number)->not->toBe('PALSU-002')->and($employee->status)->toBe(EmployeeStatus::Active);
});

test('BR-025 nomor pegawai berurutan dari NumberGenerator', function () {
    $this->actingAs($this->hrd)->postJson('/pegawai', $this->world->payload())->assertCreated();
    $this->actingAs($this->hrd)->postJson('/pegawai', $this->world->payload(['full_name' => 'Siti Aminah', 'gender' => 'P']))->assertCreated();

    [$first, $second] = Employee::query()->orderBy('id')->pluck('employee_number')->all();
    expect((int) substr($second, -4))->toBe((int) substr($first, -4) + 1);
});

test('FR-025 penugasan gagal → pegawai ikut batal (satu transaksi)', function () {
    // Jabatan dinonaktifkan di antara validasi request dan simpan: CreateAssignment menolak.
    DB::listen(function ($query) {
        if (str_starts_with($query->sql, 'insert into `employees`')) {
            DB::table('positions')->update(['is_active' => false]);
        }
    });

    $this->actingAs($this->hrd)->postJson('/pegawai', $this->world->payload())
        ->assertUnprocessable()->assertJsonValidationErrors('assignment.position_id');

    expect(Employee::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'employee.created')->exists())->toBeFalse();
});

test('BR-051 penugasan pertama: jabatan/lokasi nonaktif & atasan nonaktif ditolak', function () {
    $this->world->toko->update(['is_active' => false]);
    $inactive = Employee::factory()->inactive()->create();

    $this->actingAs($this->hrd)->postJson('/pegawai', $this->world->payload(['assignment' => [
        'location_id' => $this->world->toko->id,
        'supervisor_employee_id' => $inactive->id,
    ]]))->assertUnprocessable()->assertJsonValidationErrors(['assignment.location_id', 'assignment.supervisor_employee_id']);
});

test('BR-042 mode absen penugasan bisa menimpa jabatan', function () {
    $this->actingAs($this->hrd)->postJson('/pegawai', $this->world->payload(['assignment' => ['attendance_mode' => 'anywhere']]))
        ->assertCreated();

    expect(Assignment::query()->sole()->effectiveAttendanceMode())->toBe(AttendanceMode::Anywhere);
});

test('FR-025 ubah pegawai: NIK kosong = tetap, NIK baru = diganti, centang hapus = dihapus', function () {
    $nik = EmployeeWorld::nik();
    $employee = Employee::factory()->withNik($nik)->create();
    $cipher = app(NikCipher::class);

    $this->actingAs($this->hrd)->put("/pegawai/{$employee->id}", [...$this->world->payload(), 'nik' => ''])->assertRedirect();
    expect($employee->fresh()->nik_hash)->toBe($cipher->hash($nik));

    $new = EmployeeWorld::nik();
    $this->actingAs($this->hrd)->put("/pegawai/{$employee->id}", [...$this->world->payload(), 'nik' => $new])->assertRedirect();
    expect($employee->fresh()->nik_hash)->toBe($cipher->hash($new));

    $this->actingAs($this->hrd)->put("/pegawai/{$employee->id}", [...$this->world->payload(), 'remove_nik' => true])->assertRedirect();
    expect($employee->fresh()->nik_enc)->toBeNull()->and($employee->fresh()->nik_hash)->toBeNull();

    expect(AuditLog::query()->where('event', 'employee.updated')->count())->toBe(3);
});

test('SEC-050 foto: gambar disimpan ulang di disk privat; .php berganti nama .jpg → 422', function () {
    $this->actingAs($this->hrd)->post('/pegawai', $this->world->payload([
        'photo' => UploadedFile::fake()->createWithContent('foto.jpg', '<?php echo "x"; ?>'),
    ]), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');

    $this->actingAs($this->hrd)->post('/pegawai', $this->world->payload([
        'photo' => UploadedFile::fake()->image('foto.png', 400, 300),
    ]), ['Accept' => 'application/json'])->assertCreated();

    $employee = Employee::query()->sole();
    expect($employee->photo_path)->toStartWith('employee-photos/')->toEndWith('.webp');
    Storage::disk('local')->assertExists($employee->photo_path);

    $this->actingAs($this->hrd)->get("/pegawai/{$employee->id}/foto")
        ->assertOk()->assertHeader('Content-Type', 'image/webp');
});

test('BR-055 hapus: pegawai salah input (belum dipakai) terhapus beserta akun yang belum pernah login', function () {
    $employee = $this->world->employee();
    $account = User::factory()->create(['employee_id' => $employee->id]);

    $this->actingAs($this->hrd)->delete("/pegawai/{$employee->id}")->assertRedirect('/pegawai');

    expect(Employee::query()->find($employee->id))->toBeNull()
        ->and(Employee::withTrashed()->find($employee->id))->not->toBeNull()
        ->and(User::query()->find($account->id))->toBeNull()
        ->and(AuditLog::query()->where('event', 'employee.deleted')->exists())->toBeTrue();
});

test('BR-055 hapus ditolak bila akun pernah login / atasan / kepala lokasi, dengan arahan Nonaktifkan', function (string $case) {
    $employee = $this->world->employee();

    match ($case) {
        'login' => User::factory()->create(['employee_id' => $employee->id, 'last_login_at' => now()]),
        'atasan' => $this->world->employee(supervisor: $employee),
        'kepala' => $this->world->gudang->update(['head_employee_id' => $employee->id]),
    };

    $this->actingAs($this->hrd)->from("/pegawai/{$employee->id}")->delete("/pegawai/{$employee->id}")
        ->assertSessionHasErrors('employee');
    expect(session('errors')->first('employee'))->toEndWith('Gunakan Nonaktifkan.');

    expect(Employee::query()->find($employee->id))->not->toBeNull();

    // Tombol Hapus disembunyikan, alasannya ditampilkan.
    $this->actingAs($this->hrd)->get("/pegawai/{$employee->id}")
        ->assertInertia(fn (Assert $page) => $page->where('can.delete', false)->has('deleteBlockers', 1));
})->with(['login', 'atasan', 'kepala']);

test('07 §4 detail pegawai: header, tab, NIK termasking, penugasan aktif', function () {
    $employee = $this->world->employee(attributes: ['full_name' => 'Sri Wahyuni']);

    $this->actingAs($this->hrd)->get("/pegawai/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('employees/show')
            ->where('tab', 'profil')
            ->where('employee.full_name', 'Sri Wahyuni')
            ->where('primary.position', 'Staf Gudang')
            ->where('primary.location', 'Gudang Uji')
            ->where('can.update', true)
            ->where('can.revealNik', true)
            ->where('can.createAccount', true)
            ->has('assignments', 1));

    $this->actingAs($this->hrd)->get("/pegawai/{$employee->id}?tab=riwayat")
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'riwayat')->has('history.rows'));

    // Kunci tab lama (sebelum dikelompokkan) tetap berlaku; kunci tak dikenal → Profil.
    $this->actingAs($this->hrd)->get("/pegawai/{$employee->id}?tab=data")
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'profil'));
    $this->actingAs($this->hrd)->get("/pegawai/{$employee->id}?tab=absensi")
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'presensi'));
    $this->actingAs($this->hrd)->get("/pegawai/{$employee->id}?tab=ngawur")
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'profil'));
});

test('06 §4 kepala lokasi & supervisor hanya melihat: tambah/ubah/hapus pegawai 403', function (string $role) {
    $self = $this->world->employee();
    $actor = $this->world->actor($role, $self);
    $other = $this->world->employee(supervisor: $self);

    $this->actingAs($actor)->get('/pegawai/baru')->assertForbidden();
    $this->actingAs($actor)->get("/pegawai/{$other->id}/ubah")->assertForbidden();
    $this->actingAs($actor)->delete("/pegawai/{$other->id}")->assertForbidden();
    $this->actingAs($actor)->get("/pegawai/{$other->id}")->assertOk();
})->with(['kepala_lokasi', 'supervisor']);
