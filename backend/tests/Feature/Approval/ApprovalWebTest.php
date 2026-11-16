<?php

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalDelegation;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Notifications\ApprovalRequestedNotification;
use App\Domain\Approval\Support\ApprovalVisibility;
use App\Domain\Audit\Models\AuditLog;
use App\Exceptions\InertiaApiErrorRenderer;
use App\Models\User;
use App\Support\Api\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ApprovalWorld;
use Tests\Support\AssertsIdor;

uses(AssertsIdor::class);

/*
 * T-067 — rute web persetujuan (15 §5, PA-27, PA-31, SEC-101, SEC-033). Permintaan dibuat lewat mesin
 * (ApprovalWorld) lalu diputuskan lewat HTTP. "Sekarang" = Senin 16 Nov 2026 10:00 WIB.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Jakarta'));
    Notification::fake();
    $this->a = ApprovalWorld::make($this);
    $this->useRealPermissions();
});

test('PA-27 calon penyetuju TANPA approvals.view membuka "Menunggu saya", detail, dan memutuskan; tab lain 403', function () {
    $this->a->rule([['permission', 'purchase_orders.approve']]);
    // HRD tidak memegang approvals.view (15 §6.4) tetapi diberi izin langkah.
    $approver = $this->a->user('hrd', ['purchase_orders.approve']);
    $request = $this->a->submit($this->a->user());

    $this->actingAs($approver)->get('/persetujuan')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('approvals/index', false)
            ->where('tab', 'mine')
            ->where('rows.0.id', $request->id)
            ->where('rows.0.can.decide', true)
            ->where('rows.0.reason', 'Aturan: '.$request->rule_snapshot['name'])
            ->where('tabs', [['value' => 'mine', 'label' => 'Menunggu saya', 'count' => 1]])
            ->where('can.viewAll', false));

    $this->actingAs($approver)->get('/persetujuan?filter[tab]=all')->assertForbidden();
    $this->actingAs($approver)->get('/persetujuan?filter[tab]=submitted')->assertForbidden();

    $this->actingAs($approver)->get("/persetujuan/{$request->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('approvals/show', false)
            ->where('approval.id', $request->id)
            ->where('approval.can.decide', true)
            ->where('approval.steps.0.status', 'pending')
            ->where('approval.document_url', '/uji/'.$request->document_id));

    $this->actingAs($approver)->from("/persetujuan/{$request->id}")
        ->post("/persetujuan/{$request->id}/setujui", ['step' => 1])
        ->assertRedirect("/persetujuan/{$request->id}")
        ->assertSessionHasNoErrors();
    expect($request->refresh()->status)->toBe(ApprovalStatus::Approved);
});

test('PA-27 SEC-033 IDOR: bukan calon, bukan pengaju, tanpa approvals.view → 404 untuk show/setujui/tolak/batalkan', function () {
    $this->a->rule([['permission', 'purchase_orders.approve']]);
    $this->a->user(permissions: ['purchase_orders.approve']);
    $request = $this->a->submit($this->a->user());
    $outsider = $this->a->user('hrd');

    $this->assertOutOfScope404($outsider, 'GET', "/persetujuan/{$request->id}");
    $this->assertOutOfScope404($outsider, 'POST', "/persetujuan/{$request->id}/setujui", ['step' => 1]);
    $this->assertOutOfScope404($outsider, 'POST', "/persetujuan/{$request->id}/tolak", ['step' => 1, 'note' => 'Tidak sesuai']);
    $this->assertOutOfScope404($outsider, 'POST', "/persetujuan/{$request->id}/batalkan");
    expect($request->refresh()->status)->toBe(ApprovalStatus::Pending);
});

test('SEC-033 IDOR: pemegang approvals.view ber-scope lokasi tidak melihat permintaan lokasi lain (404) dan tab Semua tersaring', function () {
    $org = $this->a->org;
    $this->a->rule([['permission', 'purchase_orders.approve']]);
    $this->a->user(permissions: ['purchase_orders.approve']);
    $gudangStaff = $org->employee();
    $tokoStaff = $org->employee($org->toko);
    $atGudang = $this->a->submit($this->a->user(), attributes: ['scope_employee_id' => $gudangStaff->id]);
    $atToko = $this->a->submit($this->a->user(), attributes: ['scope_employee_id' => $tokoStaff->id]);
    $kgToko = $this->a->user('kepala_gudang', employee: $org->employee($org->toko));

    $this->assertOutOfScope404($kgToko, 'GET', "/persetujuan/{$atGudang->id}");
    $this->actingAs($kgToko)->get("/persetujuan/{$atToko->id}")->assertOk();
    $this->actingAs($kgToko)->get('/persetujuan?filter[tab]=all')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $atToko->id)->where('rows.0.can.decide', false));
});

test('SEC-101 maker-checker lewat HTTP: Owner pengaju → 403 APPROVAL_SELF_DECISION (JSON) / galat form + toast (Inertia)', function () {
    $this->a->rule([['permission', 'purchase_orders.approve']]);
    $this->a->user(permissions: ['purchase_orders.approve']);
    $owner = $this->a->user('owner');
    $request = $this->a->submit($owner);

    $this->actingAs($owner)->postJson("/persetujuan/{$request->id}/setujui", ['step' => 1])
        ->assertStatus(403)->assertJsonPath('error.code', 'APPROVAL_SELF_DECISION');

    $this->actingAs($owner)->from("/persetujuan/{$request->id}")
        ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post("/persetujuan/{$request->id}/setujui", ['step' => 1])
        ->assertRedirect("/persetujuan/{$request->id}")
        ->assertSessionHasErrors([InertiaApiErrorRenderer::FORM_KEY => ErrorCode::APPROVAL_SELF_DECISION->message()]);
    expect($request->refresh()->status)->toBe(ApprovalStatus::Pending);
});

test('SEC-101 keputusan kedua pada langkah yang sama lewat HTTP → 409 STATE_CONFLICT; step wajib', function () {
    $this->a->rule([['permission', 'purchase_orders.approve']]);
    $a = $this->a->user(permissions: ['purchase_orders.approve']);
    $b = $this->a->user(permissions: ['purchase_orders.approve']);
    $request = $this->a->submit($this->a->user());

    $this->actingAs($a)->postJson("/persetujuan/{$request->id}/setujui")->assertStatus(422);
    $this->actingAs($a)->postJson("/persetujuan/{$request->id}/setujui", ['step' => 1])->assertRedirect();
    $this->actingAs($b)->postJson("/persetujuan/{$request->id}/tolak", ['step' => 1, 'note' => 'Terlambat diputuskan'])
        ->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');
    expect($request->refresh()->status)->toBe(ApprovalStatus::Approved);
});

test('SEC-101 reauth HTTP: nominal ≥ approval.reauth_amount tanpa konfirmasi → galat current_password; password salah ditolak; benar → disetujui', function () {
    setSetting('approval.reauth_amount', '50000000');
    $this->a->rule([['permission', 'purchase_orders.approve']]);
    $approver = $this->a->user(permissions: ['purchase_orders.approve']);
    $request = $this->a->submit($this->a->user(), '75000000');

    $this->actingAs($approver)->get("/persetujuan/{$request->id}")
        ->assertInertia(fn (Assert $page) => $page->where('approval.requires_password', true));

    $this->actingAs($approver)->from('/persetujuan')->post("/persetujuan/{$request->id}/setujui", ['step' => 1])
        ->assertSessionHasErrors('current_password');
    $this->actingAs($approver)->from('/persetujuan')->post("/persetujuan/{$request->id}/setujui", ['step' => 1, 'current_password' => 'salah-sekali'])
        ->assertSessionHasErrors('current_password');
    expect($request->refresh()->status)->toBe(ApprovalStatus::Pending);

    $this->actingAs($approver)->from('/persetujuan')->post("/persetujuan/{$request->id}/setujui", ['step' => 1, 'current_password' => 'password'])
        ->assertSessionHasNoErrors();
    expect($request->refresh()->status)->toBe(ApprovalStatus::Approved);
});

test('BR-153(9) PA-31 pengaju membatalkan lewat admin.approvals.cancel; pengguna lain yang melihat → 403', function () {
    $this->a->rule([['permission', 'purchase_orders.approve']]);
    $approver = $this->a->user(permissions: ['purchase_orders.approve']);
    $requester = $this->a->user();
    $request = $this->a->submit($requester);

    $this->actingAs($approver)->post("/persetujuan/{$request->id}/batalkan")->assertForbidden();
    $this->actingAs($requester)->from('/persetujuan')->post("/persetujuan/{$request->id}/batalkan")->assertRedirect('/persetujuan');
    expect($request->refresh()->status)->toBe(ApprovalStatus::Cancelled);
});

test('BR-001 listing approvals: cari ringkasan/pengaju, filter status & jenis, sort nominal, tab Saya ajukan', function () {
    $this->a->rule([['permission', 'purchase_orders.approve']]);
    $approver = $this->a->user(permissions: ['purchase_orders.approve']);
    $requester = $this->a->user(attributes: ['name' => 'Rina Pembeli']);
    $first = $this->a->submit($requester, '2000');
    $second = $this->a->submit($requester, '1000');
    $this->actingAs($approver)->post("/persetujuan/{$first->id}/setujui", ['step' => 1]);
    $others = $this->a->submit($this->a->user(), '3000');

    $this->actingAs($requester)->get('/persetujuan?filter[tab]=submitted&sort=amount')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 2)
            ->where('rows.0.id', $second->id)->where('rows.0.can.cancel', true)
            ->where('rows.1.id', $first->id)->where('rows.1.can.cancel', false));

    $this->actingAs($approver)->get('/persetujuan?filter[tab]=all&filter[status][]=approved')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $first->id));

    $this->actingAs($approver)->get('/persetujuan?filter[tab]=all&q=Rina')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 2));

    $this->actingAs($approver)->get('/persetujuan?filter[tab]=all&q=UJI-3')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $others->id));

    $this->actingAs($approver)->get('/persetujuan?filter[tab]=mine&filter[document_type]=uji_dokumen&sort=-amount')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 2)->where('rows.0.id', $others->id)->where('rows.1.id', $second->id));
});

test('BR-153(7) SEC-110 delegasi: buat milik sendiri (audit), ke diri sendiri ditolak, atas nama orang lain hanya approvals.configure', function () {
    $supervisor = $this->a->user('supervisor');
    $delegate = $this->a->user();
    $other = $this->a->user();
    $payload = ['delegate_user_id' => $delegate->id, 'start_date' => '2026-11-16', 'end_date' => '2026-11-18', 'document_types' => ['uji_dokumen'], 'reason' => 'Cuti tahunan'];

    $this->actingAs($supervisor)->from('/persetujuan/delegasi')->post('/persetujuan/delegasi', [...$payload, 'delegate_user_id' => $supervisor->id])
        ->assertSessionHasErrors('delegate_user_id');
    $this->actingAs($supervisor)->from('/persetujuan/delegasi')->post('/persetujuan/delegasi', [...$payload, 'start_date' => '2026-11-15'])
        ->assertSessionHasErrors('start_date');
    $this->actingAs($supervisor)->post('/persetujuan/delegasi', [...$payload, 'delegator_user_id' => $other->id])->assertForbidden();

    $this->actingAs($supervisor)->post('/persetujuan/delegasi', $payload)->assertRedirect('/persetujuan/delegasi');
    $delegation = ApprovalDelegation::query()->sole();
    expect($delegation->delegator_user_id)->toBe($supervisor->id)
        ->and(AuditLog::query()->where('event', 'approval_delegation.created')->where('auditable_id', $delegation->id)->exists())->toBeTrue();

    $owner = $this->a->user('owner');
    $this->actingAs($owner)->post('/persetujuan/delegasi', [...$payload, 'delegator_user_id' => $other->id])->assertRedirect();
    expect(ApprovalDelegation::query()->where('delegator_user_id', $other->id)->exists())->toBeTrue();
});

test('BR-001 SEC-033 listing & cabut delegasi: hanya milik sendiri kecuali approvals.configure; cabut tercatat audit', function () {
    $mine = $this->a->user('supervisor');
    $delegate = $this->a->user();
    $stranger = $this->a->user('supervisor');
    $create = fn (User $from, User $to, string $start = '2026-11-16') => ApprovalDelegation::query()->create([
        'delegator_user_id' => $from->id, 'delegate_user_id' => $to->id, 'start_date' => $start, 'end_date' => '2026-11-30',
        'reason' => 'Cuti tahunan', 'is_active' => true,
    ]);
    $own = $create($mine, $delegate);
    $foreign = $create($stranger, $this->a->user(), '2026-11-20');

    $this->actingAs($mine)->get('/persetujuan/delegasi?filter[active_on][from]=2026-11-16&filter[active_on][to]=2026-11-17')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('approvals/delegations', false)
            ->has('rows', 1)->where('rows.0.id', $own->id)->where('rows.0.state', 'active')->where('rows.0.can.revoke', true));
    $this->actingAs($mine)->get('/persetujuan/delegasi?q='.urlencode($stranger->name))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 0));

    $owner = $this->a->user('owner');
    $this->actingAs($owner)->get('/persetujuan/delegasi?sort=start_date')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 2)->where('rows.0.id', $own->id)->where('rows.1.state', 'scheduled'));

    $this->assertOutOfScope404($mine, 'DELETE', "/persetujuan/delegasi/{$foreign->id}");
    // Penerima melihat tetapi tidak bisa mencabut.
    $this->actingAs($delegate)->delete("/persetujuan/delegasi/{$own->id}")->assertForbidden();

    $this->actingAs($mine)->delete("/persetujuan/delegasi/{$own->id}")->assertRedirect();
    expect($own->refresh()->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('event', 'approval_delegation.revoked')->where('auditable_id', $own->id)->exists())->toBeTrue();
});

test('PA-27 menu & angka: ApprovalVisibility menghitung "Menunggu saya" sama dengan tab mine', function () {
    $this->a->rule([['permission', 'purchase_orders.approve']]);
    $approver = $this->a->user(permissions: ['purchase_orders.approve']);
    $this->a->submit($this->a->user());
    $this->a->submit($this->a->user());

    expect(app(ApprovalVisibility::class)->pendingCountFor($approver))->toBe(2);
});

test('15 §9.1 notifikasi approval_* membawa tautan ke detail permintaan', function () {
    $this->a->rule([['permission', 'purchase_orders.approve']]);
    $approver = $this->a->user(permissions: ['purchase_orders.approve']);
    $request = $this->a->submit($this->a->user());

    Notification::assertSentTo($approver, ApprovalRequestedNotification::class,
        fn ($n) => $n->url() === "/persetujuan/{$request->id}" && $n->toDatabase($approver)['title'] === 'Menunggu persetujuan: Dokumen uji');
    expect(ApprovalRequest::query()->count())->toBe(1);
});
