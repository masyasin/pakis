<?php

use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Attendance\Notifications\CorrectionSubmitted;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Device\Support\DeviceTokens;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AttendanceWorld;
use Tests\Support\CorrectionWorld;

/*
 * Pengajuan koreksi presensi dari Pakis Pro (T-040): 05 §5.7/§5.7a, BR-069, BR-070, 06 §7.1.
 * Hari ini = Senin 16 Nov 2026 12:00 WIB; shift PAGI 08:00–16:00 (masuk dibuka 06:00, pulang
 * ditutup 22:00).
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 05:00:00');
    Storage::fake('local');
    Notification::fake();
    $this->c = CorrectionWorld::make($this);
});

afterEach(fn () => Carbon::setTestNow());

test('BR-069 ajukan koreksi: pending, nomor KOR-YYYYMM-SEQ, penyetuju = atasan langsung, audit, notifikasi BR-070', function () {
    $response = CorrectionWorld::submit($this, $this->c->staff)->assertCreated()
        ->assertJsonPath('data.number', 'KOR-202611-0001')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.type', 'check_in')
        ->assertJsonPath('data.proposed_check_in', '07:55')
        ->assertJsonPath('data.proposed_check_out', null)
        ->assertJsonPath('data.reason.code', 'forgot')
        ->assertJsonPath('data.approver.name', 'Atasan Uji')
        ->assertJsonPath('data.is_manual', false)
        ->assertJsonPath('data.has_attachment', false)
        ->assertJsonPath('data.submitted_at', '2026-11-16T12:00:00+07:00');
    expect($response->json('data'))->not->toHaveKey('attachment_path');

    $correction = AttendanceCorrection::query()->sole();
    expect($correction->approver_employee_id)->toBe($this->c->boss['employee']->id)
        ->and($correction->submitted_by)->toBe($this->c->staff['user']->id);

    $audit = AuditLog::query()->where('event', 'attendance_correction.submitted')->sole();
    expect($audit->new_values)->toMatchArray(['number' => 'KOR-202611-0001', 'type' => 'check_in', 'proposed_check_in' => '07:55', 'status' => 'pending'])
        ->and($audit->new_values)->not->toHaveKey('note');

    Notification::assertSentTo($this->c->boss['user'], CorrectionSubmitted::class);
});

test('BR-070 tanpa atasan aktif: notifikasi ke kepala lokasi + HRD; approver kosong', function () {
    $hrd = $this->c->w->s->org->actor('hrd');
    $kepala = $this->c->w->s->org->employee($this->c->w->s->org->gudang);
    $this->c->w->s->org->gudang->forceFill(['head_employee_id' => $kepala->id])->save();
    $kepalaUser = $this->c->w->s->org->actor('kepala_lokasi', $kepala);
    $alone = $this->c->member();

    CorrectionWorld::submit($this, $alone)->assertCreated()->assertJsonPath('data.approver', null);

    Notification::assertSentTo([$hrd, $kepalaUser], CorrectionSubmitted::class);
});

test('BR-069 batas tanggal: masa depan & lebih tua dari correction_max_days → CORRECTION_NOT_ALLOWED; batas tepat diterima', function () {
    setSetting('attendance.correction_max_days', 7);

    CorrectionWorld::submit($this, $this->c->staff, ['work_date' => '2026-11-17'])
        ->assertStatus(422)->assertJsonPath('error.code', 'CORRECTION_NOT_ALLOWED');
    CorrectionWorld::submit($this, $this->c->staff, ['work_date' => '2026-11-08'])
        ->assertStatus(422)->assertJsonPath('error.code', 'CORRECTION_NOT_ALLOWED');
    CorrectionWorld::submit($this, $this->c->staff, ['work_date' => '2026-11-09'])->assertCreated();
});

test('BR-069 jam usulan harus di jendela shift (BR-060); pulang minimal jeda setelah masuk; galat per field', function () {
    CorrectionWorld::submit($this, $this->c->staff, ['proposed_check_in' => '05:30'])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonPath('error.details.proposed_check_in.0', 'Jam masuk harus antara 06:00 dan 16:00 (shift Pagi).');
    CorrectionWorld::submit($this, $this->c->staff, ['type' => 'check_out', 'proposed_check_in' => null, 'proposed_check_out' => '23:00'])
        ->assertStatus(422)->assertJsonPath('error.details.proposed_check_out.0', 'Jam pulang harus antara 06:00 dan 22:00 (shift Pagi).');
    CorrectionWorld::submit($this, $this->c->staff, ['type' => 'both', 'proposed_check_in' => '08:00', 'proposed_check_out' => '08:03'])
        ->assertStatus(422)->assertJsonPath('error.details.proposed_check_out.0', 'Jam pulang minimal 5 menit setelah jam masuk.');
    // `both` wajib dua jam; format HH:mm.
    CorrectionWorld::submit($this, $this->c->staff, ['type' => 'both', 'proposed_check_out' => null])
        ->assertStatus(422)->assertJsonPath('error.details.proposed_check_out.0', 'Jam pulang wajib diisi.');
    CorrectionWorld::submit($this, $this->c->staff, ['proposed_check_in' => '7.55'])->assertStatus(422);
    // Alasan harus kode correction_reason aktif; keterangan minimal 10 karakter.
    CorrectionWorld::submit($this, $this->c->staff, ['reason_code' => 'resign', 'note' => 'pendek'])
        ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['reason_code', 'note']]]);

    CorrectionWorld::submit($this, $this->c->staff, ['type' => 'both', 'proposed_check_in' => '08:00', 'proposed_check_out' => '16:10'])->assertCreated();
    expect(AttendanceCorrection::query()->count())->toBe(1);
});

test('BR-069 shift lintas tengah malam: jam sebelum jendela masuk = keesokan harinya, masuk lebih awal tetap hari itu', function () {
    $malam = $this->c->w->s->shift(['code' => 'MLM', 'name' => 'Malam', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'crosses_midnight' => true]);
    $night = $this->c->member($this->c->boss['employee']);
    $this->c->w->s->override($night['employee'], '2026-11-15', $malam);
    $this->c->w->s->override($night['employee'], '2026-11-14', $malam);

    CorrectionWorld::submit($this, $night, ['work_date' => '2026-11-15', 'type' => 'both', 'proposed_check_in' => '21:50', 'proposed_check_out' => '06:05'])->assertCreated();
    // Pulang 13:00 keesokan harinya sudah lewat penutupan (06:00 + 6 jam) → ditolak.
    CorrectionWorld::submit($this, $night, ['work_date' => '2026-11-14', 'type' => 'check_out', 'proposed_check_out' => '13:00'])->assertStatus(422);
});

test('BR-069 satu pending per pegawai-tanggal → 409 CORRECTION_DUPLICATE_PENDING; tanggal lain boleh', function () {
    CorrectionWorld::submit($this, $this->c->staff)->assertCreated();
    CorrectionWorld::submit($this, $this->c->staff, ['type' => 'check_out', 'proposed_check_out' => '16:00'])
        ->assertStatus(409)->assertJsonPath('error.code', 'CORRECTION_DUPLICATE_PENDING');
    CorrectionWorld::submit($this, $this->c->staff, ['work_date' => '2026-11-13'])->assertCreated();
});

test('05 §5.7 lampiran: JPEG tanpa EXIF, PNG tanpa chunk teks, PDF diterima; teks berekstensi .jpg & terlalu besar ditolak', function () {
    CorrectionWorld::submit($this, $this->c->staff, ['attachment' => AttendanceWorld::selfie(withExif: true)])
        ->assertCreated()->assertJsonPath('data.has_attachment', true);
    $jpeg = AttendanceCorrection::query()->sole();
    expect($jpeg->attachment_path)->toStartWith('corrections/2026/11/')->toEndWith('.jpg')
        ->and(Storage::disk('local')->get((string) $jpeg->attachment_path))->not->toContain('UJI-RAHASIA');

    CorrectionWorld::submit($this, $this->c->staff, ['work_date' => '2026-11-13', 'attachment' => CorrectionWorld::pngWithText()])->assertCreated();
    $png = AttendanceCorrection::query()->where('work_date', '2026-11-13')->sole();
    expect($png->attachment_path)->toEndWith('.png')
        ->and(Storage::disk('local')->get((string) $png->attachment_path))->not->toContain('UJI-RAHASIA')
        ->and(imagecreatefromstring((string) Storage::disk('local')->get((string) $png->attachment_path)))->not->toBeFalse();

    CorrectionWorld::submit($this, $this->c->staff, ['work_date' => '2026-11-12', 'attachment' => CorrectionWorld::pdf()])->assertCreated();
    expect(AttendanceCorrection::query()->where('work_date', '2026-11-12')->value('attachment_path'))->toEndWith('.pdf');

    CorrectionWorld::submit($this, $this->c->staff, ['work_date' => '2026-11-11', 'attachment' => UploadedFile::fake()->createWithContent('bukti.jpg', 'bukan gambar')])
        ->assertStatus(422)->assertJsonPath('error.details.attachment.0', 'Lampiran harus foto JPEG/PNG atau PDF.');

    setSetting('attendance.correction_attachment_max_kb', 256);
    CorrectionWorld::submit($this, $this->c->staff, ['work_date' => '2026-11-11', 'attachment' => CorrectionWorld::pdf(300 * 1024)])
        ->assertStatus(422)->assertJsonPath('error.details.attachment.0', 'Ukuran lampiran maksimal 256 KB.');
    expect(Storage::disk('local')->allFiles('corrections'))->toHaveCount(3);
});

test('06 §7.1 batal oleh pengaju saat pending; milik orang lain → 404 (IDOR); sudah batal → 409', function () {
    $id = CorrectionWorld::submit($this, $this->c->staff)->assertCreated()->json('data.id');
    $other = $this->c->member($this->c->boss['employee']);

    CorrectionWorld::call($this, $other, 'POST', "/api/v1/attendance-corrections/{$id}/cancel")
        ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    CorrectionWorld::call($this, $this->c->staff, 'POST', "/api/v1/attendance-corrections/{$id}/cancel")
        ->assertOk()->assertJsonPath('data.status', 'cancelled');
    CorrectionWorld::call($this, $this->c->staff, 'POST', "/api/v1/attendance-corrections/{$id}/cancel")
        ->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');

    expect(AuditLog::query()->where('event', 'attendance_correction.cancelled')->count())->toBe(1);
    // Setelah batal, tanggal yang sama boleh diajukan lagi (pending_key kosong).
    CorrectionWorld::submit($this, $this->c->staff)->assertCreated();
});

test('07 §3.4 my_corrections: hanya milik sendiri, cari nomor, filter status & bulan, sort -created_at', function () {
    CorrectionWorld::submit($this, $this->c->staff, ['work_date' => '2026-10-30'])->assertStatus(422); // > 7 hari
    setSetting('attendance.correction_max_days', 30);
    CorrectionWorld::submit($this, $this->c->staff, ['work_date' => '2026-10-30'])->assertCreated();
    Carbon::setTestNow('2026-11-16 05:10:00');
    $second = CorrectionWorld::submit($this, $this->c->staff)->assertCreated()->json('data.number');
    CorrectionWorld::submit($this, $this->c->member($this->c->boss['employee']))->assertCreated();

    $list = fn (string $query) => CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/attendance-corrections'.$query)->assertOk();

    expect(array_column($list('')->json('data'), 'number'))->toBe([$second, 'KOR-202611-0001'])
        ->and(array_column($list('?q='.$second)->json('data'), 'number'))->toBe([$second])
        ->and(array_column($list('?filter[month]=2026-10')->json('data'), 'work_date'))->toBe(['2026-10-30'])
        ->and($list('?filter[status][]=approved')->json('data'))->toBe([])
        ->and(array_column($list('?sort=work_date')->json('data'), 'work_date'))->toBe(['2026-10-30', '2026-11-16']);
    expect($list('')->json('meta.pagination.total'))->toBe(2);
});

test('akun tanpa data pegawai → 403 FORBIDDEN (tidak ada presensi untuk dikoreksi)', function () {
    $user = User::factory()->withTwoFactor()->create()->assignRole('administrator');
    $device = Device::query()->create(['user_id' => $user->id, 'device_uid' => hash('sha256', 'tanpa-pegawai'), 'status' => DeviceStatus::Active, 'model' => 'Uji Android']);
    $phone = ['token' => app(DeviceTokens::class)->issue($user, $device)->plainTextToken, 'uid' => $device->device_uid];

    CorrectionWorld::submit($this, $phone)->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    CorrectionWorld::call($this, $phone, 'GET', '/api/v1/attendance-corrections')->assertForbidden();
});
