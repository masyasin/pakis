<?php

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Models\Role;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Enums\AttendanceType;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Device\Models\Device;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Models\Location;
use App\Models\User;
use App\Support\Import\SpreadsheetReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\AttendanceWorld;

/*
 * T-038 — Absensi/Presensi harian web (FR-039, SEC-013, SEC-033, 05 §4.10, 07 §3.4).
 * Hari uji: Rabu 11 Nov 2026 (WIB). Titik lokasi fiktif dari AttendanceWorld.
 */

uses(AssertsIdor::class);

const AW_TODAY = '2026-11-11';

beforeEach(function () {
    Storage::fake('local');
    $this->world = AttendanceWorld::make($this);
    $this->org = $this->world->s->org;
    $this->hrd = $this->org->actor('hrd');
    $this->travelTo(CarbonImmutable::parse(AW_TODAY.' 12:00', 'Asia/Jakarta'));
});

/**
 * Baris hari + log masuk (dengan selfie di disk privat) seperti hasil RecordAttendance.
 *
 * @param  array{employee: Employee, user: User, device: Device}  $phone
 * @param  array<string, mixed>  $attributes
 */
function awDay(array $phone, string $date = AW_TODAY, ?Location $location = null, array $attributes = []): AttendanceDay
{
    $at = CarbonImmutable::parse($date.' 08:10', 'Asia/Jakarta')->utc();
    $path = 'selfies/uji/'.Str::ulid().'.jpg';
    Storage::disk('local')->put($path, AttendanceWorld::jpegBytes(false));
    $location ??= Location::query()->findOrFail(Assignment::query()->where('employee_id', $phone['employee']->id)->value('location_id'));

    $log = AttendanceLog::query()->create([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $phone['employee']->id,
        'user_id' => $phone['user']->id,
        'device_id' => $phone['device']->id,
        'type' => AttendanceType::In,
        'recorded_at' => $at,
        'server_received_at' => $at,
        'client_time' => $at,
        'is_offline' => false,
        'latitude' => AttendanceWorld::GUDANG[0] + 0.0003,
        'longitude' => AttendanceWorld::GUDANG[1],
        'accuracy_m' => 12,
        'is_mock' => false,
        'matched_location_id' => $location->id,
        'distance_m' => 33,
        'within_radius' => true,
        'selfie_path' => $path,
    ]);

    return AttendanceDay::query()->create([
        'employee_id' => $phone['employee']->id,
        'work_date' => $date,
        'location_id' => $location->id,
        'position_id' => Assignment::query()->where('employee_id', $phone['employee']->id)->value('position_id'),
        'department_id' => test()->org->department->id,
        'check_in_log_id' => $log->id,
        'check_in_at' => $at,
        'late_minutes' => 10,
        'status' => AttendanceStatus::Late,
        ...$attributes,
    ]);
}

/** URL foto bertanda tangan dari halaman detail (seperti yang dilihat browser). */
function awSelfieUrl(User $viewer, AttendanceDay $day): ?string
{
    $url = null;
    test()->actingAs($viewer)->get("/absensi/{$day->id}")->assertOk()
        ->assertInertia(function (Assert $page) use (&$url) {
            $url = $page->toArray()['props']['logs']['in']['selfie_url'];
        });

    return $url;
}

test('FR-039 AttendanceWeb: filter tanggal default hari ini; filter status, lokasi, penanda; cari nomor; urut telat', function () {
    $a = $this->world->phone();
    $b = $this->world->phone($this->org->toko);
    awDay($a, '2026-11-10');
    awDay($a, AW_TODAY, attributes: ['late_minutes' => 25]);
    awDay($b, AW_TODAY, attributes: ['late_minutes' => 0, 'status' => AttendanceStatus::Present, 'is_offline' => true, 'has_anomaly' => true]);

    $this->actingAs($this->hrd)->get('/absensi')->assertInertia(fn (Assert $page) => $page
        ->component('attendance/index')
        ->has('rows', 2)
        ->where('listing.state.filters.work_date.from', AW_TODAY)
        ->where('rows.0.work_date', AW_TODAY));

    $range = 'filter[work_date][from]=2026-11-10&filter[work_date][to]='.AW_TODAY;
    $this->actingAs($this->hrd)->get("/absensi?{$range}")->assertInertia(fn (Assert $page) => $page->has('rows', 3));
    $this->actingAs($this->hrd)->get("/absensi?{$range}&filter[status][]=present")->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.employee.id', $b['employee']->id));
    $this->actingAs($this->hrd)->get("/absensi?filter[location]={$this->org->toko->id}")->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.location', 'Toko Uji'));
    $this->actingAs($this->hrd)->get('/absensi?filter[is_offline]=1')->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.is_offline', true));
    $this->actingAs($this->hrd)->get('/absensi?filter[has_anomaly]=0')->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.employee.id', $a['employee']->id));
    $this->actingAs($this->hrd)->get("/absensi?filter[department]={$this->org->department->id}")->assertInertia(fn (Assert $page) => $page->has('rows', 2));
    $this->actingAs($this->hrd)->get('/absensi?q='.$b['employee']->employee_number)->assertInertia(fn (Assert $page) => $page->has('rows', 1));
    $this->actingAs($this->hrd)->get('/absensi?sort=-late_minutes')->assertInertia(fn (Assert $page) => $page->where('rows.0.late_minutes', 25));
});

test('SEC-033 AttendanceWeb: scope lokasi memakai snapshot hari itu, bukan penugasan sekarang; di luar scope → 404', function () {
    $head = $this->org->employee($this->org->gudang);
    $kepala = $this->org->actor('kepala_lokasi', $head);
    $moved = $this->world->phone();
    $past = awDay($moved, '2026-11-10', $this->org->gudang);
    // Sejak 11 Nov dimutasi ke toko: hari itu milik lokasi toko.
    $today = awDay($moved, AW_TODAY, $this->org->toko);
    $range = 'filter[work_date][from]=2026-11-10&filter[work_date][to]='.AW_TODAY;

    $this->actingAs($kepala)->get("/absensi?{$range}")->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $past->id));
    $this->actingAs($kepala)->get("/absensi/{$past->id}")->assertOk();
    $this->assertOutOfScope404($kepala, 'GET', "/absensi/{$today->id}");

    // Supervisor: hanya tim (bawahan lewat penugasan).
    $boss = $this->org->employee($this->org->toko);
    $supervisor = $this->org->actor('supervisor', $boss);
    $member = $this->world->phone($this->org->toko);
    Assignment::query()->where('employee_id', $member['employee']->id)->update(['supervisor_employee_id' => $boss->id]);
    $mine = awDay($member);
    app()->forgetScopedInstances();
    $this->actingAs($supervisor)->get('/absensi')->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $mine->id));
    $this->assertOutOfScope404($supervisor, 'GET', "/absensi/{$today->id}");
});

test('SEC-013 AttendanceWeb: selfie hanya lewat URL bertanda tangan 5 menit, pemegang view_photo, dalam scope', function () {
    $phone = $this->world->phone();
    $day = awDay($phone);
    $url = awSelfieUrl($this->hrd, $day);
    expect($url)->toContain('/absensi/foto/')->toContain('signature=');

    $this->actingAs($this->hrd)->get($url)->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

    // Tanpa tanda tangan / tanda tangan diubah.
    $this->actingAs($this->hrd)->get("/absensi/foto/{$day->check_in_log_id}")->assertForbidden();
    $this->actingAs($this->hrd)->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();

    // Pemegang attendance.view tanpa view_photo (Administrator): 403 walau URL sah.
    $this->actingAs($this->org->actor('administrator'))->get($url)->assertForbidden();

    // Pemegang view_photo di luar scope: 404 (keberadaan foto tidak bocor).
    $otherHead = $this->org->employee($this->org->toko);
    $this->assertOutOfScope404($this->org->actor('kepala_lokasi', $otherHead), 'GET', $url);

    // Kedaluwarsa setelah 5 menit.
    $this->travel(6)->minutes();
    $this->actingAs($this->hrd)->get($url)->assertForbidden();
});

test('SEC-013 AttendanceWeb: log yang tidak dirujuk hari terlihat atau fotonya sudah dihapus → 404', function () {
    $phone = $this->world->phone();
    $day = awDay($phone);
    $url = (string) awSelfieUrl($this->hrd, $day);

    Storage::disk('local')->delete((string) AttendanceLog::query()->findOrFail($day->check_in_log_id)->selfie_path);
    $this->actingAs($this->hrd)->get($url)->assertNotFound();

    // Log yatim (mis. pulang yang sudah digantikan): tidak bisa diakses lewat foto.
    $orphan = AttendanceLog::query()->findOrFail($day->check_in_log_id)->replicate(['client_uuid']);
    $orphan->client_uuid = (string) Str::uuid();
    $orphan->save();
    $signed = URL::temporarySignedRoute('admin.attendance.selfie', now()->addMinutes(5), ['log' => $orphan->id]);
    $this->actingAs($this->hrd)->get($signed)->assertNotFound();
});

test('06 §4 AttendanceWeb: koordinat, diagram & foto hanya untuk view_photo (keputusan Mas 15 Nov 2026)', function () {
    $day = awDay($this->world->phone());

    $this->actingAs($this->hrd)->get("/absensi/{$day->id}")->assertInertia(fn (Assert $page) => $page
        ->component('attendance/show')
        ->where('can.view_photo', true)
        ->where('logs.in.distance_m', 33)
        ->has('logs.in.map.point')
        ->where('logs.in.map.area.radius_m', 100)
        ->where('logs.out', null));

    $this->actingAs($this->org->actor('administrator'))->get("/absensi/{$day->id}")->assertInertia(fn (Assert $page) => $page
        ->where('can.view_photo', false)
        ->where('logs.in.distance_m', 33)
        ->where('logs.in.selfie_url', null)
        ->where('logs.in.map', null));
});

test('06 §4 AttendanceWeb: view_photo per role (route bertanda tangan, matriks khusus)', function (string $role, bool $granted) {
    $phone = $this->world->phone();
    $day = awDay($phone);
    $signed = URL::temporarySignedRoute('admin.attendance.selfie', now()->addMinutes(5), ['log' => $day->check_in_log_id]);

    // Pelaku dalam scope: kepala lokasi gudang / atasan langsung pegawai itu.
    $self = $this->org->employee($this->org->gudang);
    Assignment::query()->where('employee_id', $phone['employee']->id)->update(['supervisor_employee_id' => $self->id]);
    app()->forgetScopedInstances();
    $actor = $this->org->actor($role, $self);

    $status = $this->actingAs($actor)->get($signed)->getStatusCode();
    expect($status)->toBe($granted ? 200 : 403);
})->with([
    ['owner', true], ['hrd', true], ['kepala_lokasi', true], ['supervisor', true],
    ['administrator', false], ['pegawai', false],
]);

test('SEC-051 AttendanceWeb: ekspor ber-scope tanpa koordinat, tercatat di audit', function () {
    awDay($this->world->phone());

    $response = $this->actingAs($this->hrd)->get('/absensi/ekspor');
    $response->assertOk();
    expect((string) $response->headers->get('content-disposition'))->toContain('.xlsx')
        ->and(AuditLog::query()->where('event', 'export.created')->exists())->toBeTrue();

    $path = tempnam(sys_get_temp_dir(), 'absen').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $rows = iterator_to_array((new SpreadsheetReader)->rows($path, 'xlsx'), false);
    expect($rows[0])->toContain('Nama', 'Status', 'Anomali')->not->toContain('Latitude')
        ->and(count($rows))->toBe(2);

    $this->actingAs($this->org->actor('administrator'))->get('/absensi/ekspor')->assertForbidden();
    $this->actingAs($this->org->actor('pegawai', $this->org->employee()))->get('/absensi')->assertForbidden();
});

test('T-038 AttendanceWeb: tab Presensi di detail pegawai — data hanya saat tab dibuka, ber-scope snapshot, tanpa izin = null', function () {
    $phone = $this->world->phone();
    awDay($phone, '2026-11-10');
    awDay($phone, AW_TODAY, $this->org->toko, ['status' => AttendanceStatus::Absent, 'late_minutes' => 0]);
    $url = "/pegawai/{$phone['employee']->id}";

    $this->actingAs($this->hrd)->get($url)->assertInertia(fn (Assert $page) => $page->where('attendance', null));
    $this->actingAs($this->hrd)->get("{$url}?tab=presensi")->assertInertia(fn (Assert $page) => $page
        ->has('attendance.rows', 2)
        ->where('attendance.rows.0.work_date', AW_TODAY)
        ->where('attendance.summary.late', 1)
        ->where('attendance.summary.absent', 1));

    // Kepala gudang: hanya hari yang snapshot-nya di gudang.
    $kepala = $this->org->actor('kepala_lokasi', $this->org->employee($this->org->gudang));
    $this->actingAs($kepala)->get("{$url}?tab=presensi")->assertInertia(fn (Assert $page) => $page
        ->has('attendance.rows', 1)
        ->where('attendance.rows.0.work_date', '2026-11-10'));

    // Pemegang employees.view tanpa attendance.view → null (tab memberi tahu).
    $role = Role::query()->create(['name' => 'lihat_pegawai', 'label' => 'Lihat pegawai', 'guard_name' => 'web', 'data_scope' => DataScope::All]);
    $role->givePermissionTo('employees.view');
    $viewer = User::factory()->withTwoFactor()->create()->assignRole('lihat_pegawai');
    $this->actingAs($viewer)->get("{$url}?tab=presensi")->assertInertia(fn (Assert $page) => $page->where('attendance', null));
});

test('BR-071 AttendanceWeb: penanda tugas luar kota = keterangan, selisih jam HP = peringatan', function () {
    $day = awDay($this->world->phone());
    AttendanceLog::query()->whereKey($day->check_in_log_id)->update(['flags' => json_encode([
        'field_trip_id' => 7, 'field_trip_rule' => 'destination', 'field_trip_place' => 'Gudang Mitra Uji', 'clock_skew_s' => 600,
    ])]);

    $this->actingAs($this->hrd)->get("/absensi/{$day->id}")->assertInertia(fn (Assert $page) => $page
        ->where('logs.in.notes.0.tone', 'info')
        ->where('logs.in.notes.0.text', 'Tugas luar kota: radius titik tujuan (Gudang Mitra Uji)')
        ->where('logs.in.notes.1.tone', 'warning')
        ->where('logs.in.notes.1.text', 'Jam HP berselisih 10 menit dari server'));
});
