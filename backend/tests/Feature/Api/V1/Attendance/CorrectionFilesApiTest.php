<?php

use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AttendanceWorld;
use Tests\Support\CorrectionWorld;

/*
 * Keputusan Mas 16 Nov 2026 (tindak lanjut T-041–T-045, 12): API v1 untuk Pakis Pro —
 * lookup alasan koreksi dari referensi, unduh lampiran koreksi (pengaju / penyetuju berwenang,
 * MOB-09), dan foto profil milik sendiri. Hari ini = Senin 16 Nov 2026 12:00 WIB. Data fiktif.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 05:00:00');
    Storage::fake('local');
    Notification::fake();
    $this->c = CorrectionWorld::make($this);
});

afterEach(fn () => Carbon::setTestNow());

test('FR-038 CorrectionFiles: lookup alasan koreksi = referensi aktif urut sort_order, alasan nonaktif hilang', function () {
    $codes = CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/lookup/correction-reasons')
        ->assertOk()->assertJsonStructure(['data' => [['code', 'name']], 'meta' => ['request_id']])
        ->json('data.*.code');
    expect($codes)->toContain('forgot', 'phone_broken');

    ReferenceItem::query()->forGroup(ReferenceGroup::CorrectionReason)->where('code', 'forgot')->update(['is_active' => false]);
    $after = CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/lookup/correction-reasons')->json('data.*.code');
    expect($after)->not->toContain('forgot')->and(count($after))->toBe(count($codes) - 1);
});

test('SEC-033 CorrectionFiles: lampiran untuk pengaju & atasan berwenang; pegawai lain/lokasi lain → 404', function () {
    $id = CorrectionWorld::submit($this, $this->c->staff, ['attachment' => CorrectionWorld::pdf()])->assertCreated()->json('data.id');
    $uri = "/api/v1/attendance-corrections/{$id}/attachment";

    $own = CorrectionWorld::call($this, $this->c->staff, 'GET', $uri)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect((string) $own->headers->get('content-disposition'))->toStartWith('attachment')
        ->and((string) $own->headers->get('cache-control'))->toContain('no-store');

    // Atasan langsung (penyetuju di HP, MOB-09) — juga setelah diputuskan.
    CorrectionWorld::call($this, $this->c->boss, 'GET', $uri)->assertOk();
    CorrectionWorld::call($this, $this->c->boss, 'POST', "/api/v1/approvals/attendance-corrections/{$id}/approve")->assertOk();
    CorrectionWorld::call($this, $this->c->boss, 'GET', $uri)->assertOk();

    // Rekan setim & kepala lokasi lain: tidak berwenang → 404 tanpa membocorkan keberadaan.
    $peer = $this->c->member($this->c->boss['employee']);
    CorrectionWorld::call($this, $peer, 'GET', $uri)->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    $kepalaToko = $this->c->phoneFor($this->c->w->s->org->employee($this->c->w->s->org->toko), 'kepala_lokasi');
    CorrectionWorld::call($this, $kepalaToko, 'GET', $uri)->assertNotFound();

    // Tanpa lampiran / tidak ada → 404.
    $plain = CorrectionWorld::submit($this, $this->c->staff, ['work_date' => '2026-11-13'])->assertCreated()->json('data.id');
    CorrectionWorld::call($this, $this->c->staff, 'GET', "/api/v1/attendance-corrections/{$plain}/attachment")->assertNotFound();
    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/attendance-corrections/999999/attachment')->assertNotFound();
});

test('FR-030 CorrectionFiles: foto profil milik sendiri; tanpa foto → 404', function () {
    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/me/avatar')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');

    $image = imagecreatetruecolor(64, 64);
    ob_start();
    imagejpeg($image);
    $jpeg = UploadedFile::fake()->createWithContent('foto.jpg', (string) ob_get_clean());
    app('auth')->forgetGuards();
    $this->post('/api/v1/me', ['_method' => 'PUT', 'avatar' => $jpeg], AttendanceWorld::headers($this->c->staff))->assertOk();

    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/me/avatar')->assertOk()
        ->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    // Akun lain tetap 404 (bukan foto milik orang lain).
    CorrectionWorld::call($this, $this->c->boss, 'GET', '/api/v1/me/avatar')->assertNotFound();
});
