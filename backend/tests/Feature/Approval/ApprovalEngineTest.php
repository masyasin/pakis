<?php

use App\Domain\Approval\Actions\CancelApproval;
use App\Domain\Approval\Actions\CreateDelegation;
use App\Domain\Approval\Actions\DecideApprovalStep;
use App\Domain\Approval\Actions\RequestApproval;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Enums\StepStatus;
use App\Domain\Approval\Models\ApprovalDelegation;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Models\ApprovalRule;
use App\Domain\Approval\Notifications\ApprovalDecidedNotification;
use App\Domain\Approval\Notifications\ApprovalEscalatedNotification;
use App\Domain\Approval\Notifications\ApprovalRequestedNotification;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Employee\Models\Assignment;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Approval\TestDocument;
use Tests\Support\ApprovalWorld;

/*
 * T-067 — mesin approval generik (BR-153, SEC-101, ADR-105) lewat Action, tanpa HTTP. Dokumen =
 * fixture TestDocument. "Sekarang" = Senin 16 Nov 2026 10:00 WIB. Semua data fiktif.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Jakarta'));
    Notification::fake();
    $this->a = ApprovalWorld::make($this);
});

function aprDecide(User $actor, ApprovalRequest $request, bool $approve = true, ?string $note = null, bool $password = true, ?int $step = null): ApprovalRequest
{
    $request->refresh();
    $action = app(DecideApprovalStep::class);
    $step ??= $request->current_step;

    return $approve
        ? $action->approve($actor, $request->id, $step, $note, $password)
        : $action->reject($actor, $request->id, $step, (string) $note, $password);
}

function aprCode(callable $call): ?ErrorCode
{
    try {
        $call();
    } catch (ApiError $e) {
        return $e->errorCode;
    }

    return null;
}

test('BR-153(1) batas bawah inklusif, batas atas eksklusif: Rp 24.999.999,99 → aturan 1 langkah, Rp 25.000.000 → 2 langkah', function () {
    $this->a->rule([['permission', 'purchase_orders.approve']], max: '25000000', attributes: ['name' => 'Kecil']);
    $this->a->rule([['permission', 'purchase_orders.approve'], ['permission', 'purchase_orders.approve_high']], min: '25000000', attributes: ['name' => 'Besar']);
    $requester = $this->a->user();

    $small = $this->a->submit($requester, '24999999.99');
    $big = $this->a->submit($requester, '25000000');

    expect($small->rule_snapshot['name'])->toBe('Kecil')
        ->and($small->steps)->toHaveCount(1)
        ->and($big->rule_snapshot['name'])->toBe('Besar')
        ->and($big->steps)->toHaveCount(2)
        ->and($big->steps[0]->status)->toBe(StepStatus::Pending)
        ->and($big->steps[1]->status)->toBe(StepStatus::Waiting);
});

test('BR-153(1) prioritas terkecil menang, kondisi harus cocok, aturan nonaktif diabaikan', function () {
    $this->a->rule([['permission', 'purchase_orders.approve']], attributes: ['name' => 'Umum', 'priority' => 100]);
    $this->a->rule([['permission', 'purchase_orders.approve_high']], attributes: ['name' => 'Area 3', 'priority' => 50, 'conditions' => ['sales_area_id' => [3]]]);
    $this->a->rule([['permission', 'purchase_orders.approve_high']], attributes: ['name' => 'Nonaktif', 'priority' => 1, 'is_active' => false]);
    $requester = $this->a->user();

    expect($this->a->submit($requester, '1000', ['sales_area_id' => 3])->rule_snapshot['name'])->toBe('Area 3')
        ->and($this->a->submit($requester, '1000', ['sales_area_id' => 4])->rule_snapshot['name'])->toBe('Umum');
});

test('BR-153(1) tanpa aturan cocok & auto_approve_when_no_rule=false → 422 APPROVAL_RULE_MISSING; true → langsung disetujui', function () {
    $requester = $this->a->user();
    setSetting('approval.auto_approve_when_no_rule', false);

    expect(aprCode(fn () => $this->a->submit($requester)))->toBe(ErrorCode::APPROVAL_RULE_MISSING)
        ->and(ErrorCode::APPROVAL_RULE_MISSING->status())->toBe(422)
        ->and(ApprovalRequest::query()->count())->toBe(0);

    setSetting('approval.auto_approve_when_no_rule', true);
    $document = $this->a->document('1000', ['created_by' => $requester->id]);
    expect(app(RequestApproval::class)->handle($document, $requester))->toBeNull()
        ->and($document->refresh()->status)->toBe('approved')
        ->and(ApprovalRequest::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'approval_request.auto_approved')->count())->toBe(1);
});

test('BR-153 satu permintaan aktif per dokumen → 409 APPROVAL_ALREADY_PENDING', function () {
    $this->a->rule([['permission', 'purchase_orders.approve']]);
    $requester = $this->a->user();
    $document = $this->a->document();
    $engine = app(RequestApproval::class);
    $engine->handle($document, $requester);

    expect(aprCode(fn () => $engine->handle($document, $requester)))->toBe(ErrorCode::APPROVAL_ALREADY_PENDING)
        ->and(ApprovalRequest::query()->count())->toBe(1);
});

test('BR-153(2) aturan diedit setelah diajukan: rantai permintaan berjalan tetap snapshot lama', function () {
    $rule = $this->a->rule([['permission', 'purchase_orders.approve']]);
    $approver = $this->a->user(permissions: ['purchase_orders.approve']);
    $request = $this->a->submit($this->a->user());

    $rule->steps()->delete();
    $rule->steps()->create(['step_order' => 1, 'approver_type' => 'permission', 'permission_name' => 'purchase_orders.approve_high']);
    $rule->steps()->create(['step_order' => 2, 'approver_type' => 'location_head']);
    $rule->update(['name' => 'Sudah diubah']);

    $request->refresh();
    expect($request->rule_snapshot['steps'])->toHaveCount(1)
        ->and($request->rule_snapshot['name'])->not->toBe('Sudah diubah')
        ->and($request->steps()->count())->toBe(1);

    $done = aprDecide($approver, $request);
    expect($done->status)->toBe(ApprovalStatus::Approved);
});

test('BR-153(3) rantai 2 langkah: langkah 2 aktif setelah langkah 1, notifikasi ke calon & pengaju, dokumen disetujui', function () {
    $this->a->rule([['permission', 'purchase_orders.approve'], ['permission', 'purchase_orders.approve_high']]);
    $first = $this->a->user(permissions: ['purchase_orders.approve']);
    $second = $this->a->user(permissions: ['purchase_orders.approve_high']);
    $requester = $this->a->user();
    $request = $this->a->submit($requester);

    Notification::assertSentTo($first, ApprovalRequestedNotification::class);
    Notification::assertNotSentTo($second, ApprovalRequestedNotification::class);

    $request = aprDecide($first, $request, note: 'Cocok dengan anggaran');
    expect($request->status)->toBe(ApprovalStatus::Pending)->and($request->current_step)->toBe(2);
    Notification::assertSentTo($second, ApprovalRequestedNotification::class);

    $request = aprDecide($second, $request);
    expect($request->status)->toBe(ApprovalStatus::Approved)
        ->and(TestDocument::query()->find($request->document_id)->status)->toBe('approved')
        ->and($request->steps()->pluck('decided_by')->all())->toBe([$first->id, $second->id]);
    Notification::assertSentTo($requester, ApprovalDecidedNotification::class);

    expect(AuditLog::query()->where('auditable_id', $request->id)->pluck('event')->all())
        ->toContain('approval_request.requested', 'approval_request.approved');
});

test('BR-153(8) ditolak di langkah 2 → seluruh permintaan rejected, dokumen rejected; alasan tolak minimal 5 karakter', function () {
    $this->a->rule([['permission', 'purchase_orders.approve'], ['permission', 'purchase_orders.approve_high']]);
    $first = $this->a->user(permissions: ['purchase_orders.approve']);
    $second = $this->a->user(permissions: ['purchase_orders.approve_high']);
    $request = aprDecide($first, $this->a->submit($this->a->user()));

    expect(fn () => aprDecide($second, $request, false, 'abc'))->toThrow(ValidationException::class);

    $request = aprDecide($second, $request, false, 'Harga terlalu tinggi');
    expect($request->status)->toBe(ApprovalStatus::Rejected)
        ->and(TestDocument::query()->find($request->document_id)->status)->toBe('rejected')
        ->and($request->steps()->orderBy('step_order')->pluck('status')->map->value->all())->toBe(['approved', 'rejected']);
});

test('BR-153(9) pengaju membatalkan sebelum ada keputusan; setelah langkah 1 diputuskan → 409; bukan pengaju → 403', function () {
    $this->a->rule([['permission', 'purchase_orders.approve'], ['permission', 'purchase_orders.approve_high']]);
    $first = $this->a->user(permissions: ['purchase_orders.approve']);
    $requester = $this->a->user();
    $cancel = app(CancelApproval::class);

    $request = $this->a->submit($requester);
    expect(aprCode(fn () => $cancel->handle($first, $request->id)))->toBe(ErrorCode::FORBIDDEN);
    $cancelled = $cancel->handle($requester, $request->id);
    expect($cancelled->status)->toBe(ApprovalStatus::Cancelled)
        ->and(TestDocument::query()->find($request->document_id)->status)->toBe('draft')
        ->and(AuditLog::query()->where('event', 'approval_request.cancelled')->count())->toBe(1);

    $other = aprDecide($first, $this->a->submit($requester));
    expect(aprCode(fn () => $cancel->handle($requester, $other->id)))->toBe(ErrorCode::STATE_CONFLICT);
});

test('BR-153(3) direct_supervisor memakai atasan penugasan UTAMA walau penugasan rangkap punya atasan lain', function () {
    $org = $this->a->org;
    $boss = $org->employee();
    $otherBoss = $org->employee($org->toko);
    $staff = $org->employee(supervisor: $boss);
    Assignment::query()->create([
        'employee_id' => $staff->id, 'position_id' => $org->staff->id, 'location_id' => $org->toko->id,
        'supervisor_employee_id' => $otherBoss->id, 'is_primary' => false, 'start_date' => '2026-01-01',
    ]);
    $this->a->rule([['direct_supervisor']]);
    $bossUser = $this->a->user('supervisor', employee: $boss);
    $otherBossUser = $this->a->user('supervisor', employee: $otherBoss);

    $request = $this->a->submit($this->a->user(), attributes: ['scope_employee_id' => $staff->id]);

    expect($request->steps[0]->resolved_employee_id)->toBe($boss->id)
        ->and($request->steps[0]->escalated)->toBeFalse()
        ->and(aprCode(fn () => aprDecide($otherBossUser, $request)))->not->toBeNull();
    expect(aprDecide($bossUser, $request)->status)->toBe(ApprovalStatus::Approved);
});

test('BR-153(5) PA-28 direct_supervisor tanpa atasan → langsung tereskalasi ke escalation_permission, atau approvals.configure bila kosong', function () {
    $org = $this->a->org;
    $lonely = $org->employee();
    $this->a->rule([['approver_type' => 'direct_supervisor', 'escalation_permission' => 'purchase_orders.approve_high']], attributes: ['name' => 'Dengan eskalasi', 'conditions' => ['sales_area_id' => [1]]]);
    $this->a->rule([['direct_supervisor']], attributes: ['name' => 'Tanpa eskalasi', 'conditions' => ['sales_area_id' => [2]]]);
    $high = $this->a->user(permissions: ['purchase_orders.approve_high']);
    $owner = $this->a->user('owner');
    $plain = $this->a->user();

    $withEsc = $this->a->submit($plain, attributes: ['scope_employee_id' => $lonely->id, 'sales_area_id' => 1]);
    expect($withEsc->steps[0]->resolved_employee_id)->toBeNull()
        ->and($withEsc->steps[0]->escalated)->toBeTrue();
    Notification::assertSentTo($high, ApprovalEscalatedNotification::class);
    expect(aprDecide($high, $withEsc)->status)->toBe(ApprovalStatus::Approved);

    $fallback = $this->a->submit($plain, attributes: ['scope_employee_id' => $lonely->id, 'sales_area_id' => 2]);
    expect($fallback->steps[0]->escalated)->toBeTrue()
        ->and(aprCode(fn () => aprDecide($high, $fallback)))->toBe(ErrorCode::STATE_CONFLICT)
        ->and(aprDecide($owner, $fallback)->status)->toBe(ApprovalStatus::Approved);
});

test('BR-153(4) satu-satunya calon penyetuju adalah pengaju → langkah langsung tereskalasi (bukan disetujui otomatis)', function () {
    $this->a->rule([['permission', 'purchase_orders.approve']]);
    $requester = $this->a->user(permissions: ['purchase_orders.approve']);

    $request = $this->a->submit($requester);
    // Owner (pemegang approvals.configure) baru ada setelah langkah aktif: calon dihitung saat memutuskan.
    $owner = $this->a->user('owner');

    expect($request->status)->toBe(ApprovalStatus::Pending)
        ->and($request->steps[0]->escalated)->toBeTrue()
        ->and(aprCode(fn () => aprDecide($requester, $request)))->toBe(ErrorCode::APPROVAL_SELF_DECISION)
        ->and(aprDecide($owner, $request)->status)->toBe(ApprovalStatus::Approved);
});

test('BR-153(6) eskalasi setelah N jam KALENDER (malam & akhir pekan dihitung), idempoten, tidak pernah menyetujui otomatis', function () {
    // Jumat 20 Nov 2026 22:00 WIB: batas 2 jam jatuh Sabtu 00:00 (bukan jam kerja Senin).
    $this->travelTo(CarbonImmutable::parse('2026-11-20 22:00', 'Asia/Jakarta'));
    $this->a->rule([['approver_type' => 'permission', 'permission_name' => 'purchase_orders.approve', 'escalate_after_hours' => 2, 'escalation_permission' => 'purchase_orders.approve_high']]);
    $approver = $this->a->user(permissions: ['purchase_orders.approve']);
    $high = $this->a->user(permissions: ['purchase_orders.approve_high']);
    $request = $this->a->submit($this->a->user());

    $this->travel(119)->minutes();
    $this->artisan('approvals:escalate')->assertSuccessful();
    expect($request->steps()->first()->escalated)->toBeFalse()
        ->and(aprCode(fn () => aprDecide($high, $request)))->toBe(ErrorCode::STATE_CONFLICT);

    $this->travel(1)->minutes();
    $this->artisan('approvals:escalate')->expectsOutputToContain('Langkah tereskalasi: 1')->assertSuccessful();
    $this->artisan('approvals:escalate')->expectsOutputToContain('Langkah tereskalasi: 0')->assertSuccessful();

    $request->refresh();
    expect($request->status)->toBe(ApprovalStatus::Pending)
        ->and($request->steps()->first()->escalated)->toBeTrue()
        ->and($request->steps()->first()->status)->toBe(StepStatus::Pending)
        ->and(AuditLog::query()->where('event', 'approval_request.escalated')->count())->toBe(1);
    Notification::assertSentTo($high, ApprovalEscalatedNotification::class);
    Notification::assertSentTo($approver, ApprovalEscalatedNotification::class);

    // Penyetuju asli tetap boleh; eskalasi hanya menambah.
    expect(aprDecide($approver, $request)->status)->toBe(ApprovalStatus::Approved);
});

test('BR-153(6) tanpa escalate_after_hours langkah memakai setting approval.default_escalation_hours', function () {
    setSetting('approval.default_escalation_hours', 5);
    $this->a->rule([['permission', 'purchase_orders.approve']]);
    $this->a->user(permissions: ['purchase_orders.approve']);

    $request = $this->a->submit($this->a->user());

    expect($request->steps[0]->escalate_at->toIso8601String())->toBe(now()->addHours(5)->toIso8601String());
});

test('BR-153(7) delegasi berlaku hanya di dalam tanggal & jenis dokumen, keputusan tercatat atas nama pemberi', function () {
    $this->a->rule([['permission', 'purchase_orders.approve']]);
    $owner = $this->a->user('owner');
    $delegate = $this->a->user('purchasing');
    $requester = $this->a->user();
    app(CreateDelegation::class)->handle([
        'delegator_user_id' => $owner->id, 'delegate_user_id' => $delegate->id,
        'start_date' => '2026-11-16', 'end_date' => '2026-11-18', 'document_types' => [TestDocument::TYPE], 'reason' => 'Owner cuti',
    ], $owner->id);

    $request = $this->a->submit($requester);
    Notification::assertSentTo($delegate, ApprovalRequestedNotification::class);
    $done = aprDecide($delegate, $request);
    expect($done->status)->toBe(ApprovalStatus::Approved)
        ->and($done->steps()->first()->acting_for_user_id)->toBe($owner->id)
        ->and($done->steps()->first()->decided_by)->toBe($delegate->id);

    // Hari ke-4 (UAT-A02): delegasi lewat, tidak memberi hak.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00', 'Asia/Jakarta'));
    $late = $this->a->submit($requester);
    expect(aprCode(fn () => aprDecide($delegate, $late)))->toBe(ErrorCode::STATE_CONFLICT);

    // Delegasi untuk jenis dokumen lain tidak berlaku.
    ApprovalDelegation::query()->update(['end_date' => '2026-11-30', 'document_types' => json_encode(['purchase_order'])]);
    expect(aprCode(fn () => aprDecide($delegate, $late)))->toBe(ErrorCode::STATE_CONFLICT);
});

test('BR-153(7) SEC-101 delegasi tidak berantai dan tidak boleh ke diri sendiri', function () {
    $this->a->rule([['permission', 'purchase_orders.approve']]);
    $owner = $this->a->user('owner');
    $b = $this->a->user();
    $c = $this->a->user();
    $create = app(CreateDelegation::class);
    $data = fn (User $from, User $to) => [
        'delegator_user_id' => $from->id, 'delegate_user_id' => $to->id,
        'start_date' => '2026-11-16', 'end_date' => '2026-11-20', 'document_types' => null, 'reason' => 'Cuti tahunan',
    ];

    expect(aprCode(fn () => $create->handle($data($owner, $owner), $owner->id)))->toBe(ErrorCode::VALIDATION_FAILED);

    $create->handle($data($owner, $b), $owner->id);
    $create->handle($data($b, $c), $b->id);
    $request = $this->a->submit($this->a->user());

    expect(aprCode(fn () => aprDecide($c, $request)))->toBe(ErrorCode::STATE_CONFLICT)
        ->and(aprDecide($b, $request)->steps()->first()->acting_for_user_id)->toBe($owner->id);
});

test('BR-153(4) SEC-101 maker-checker: pengaju, pembuat dokumen, dan delegasi pengaju → 403 APPROVAL_SELF_DECISION, termasuk Owner', function () {
    $this->a->rule([['permission', 'purchase_orders.approve']]);
    $owner = $this->a->user('owner');
    $creator = $this->a->user(permissions: ['purchase_orders.approve']);
    $other = $this->a->user(permissions: ['purchase_orders.approve']);

    // Owner mengajukan sendiri: memegang semua izin, tetap tidak boleh memutuskan.
    $ownRequest = $this->a->submit($owner);
    expect(aprCode(fn () => aprDecide($owner, $ownRequest)))->toBe(ErrorCode::APPROVAL_SELF_DECISION)
        ->and(ErrorCode::APPROVAL_SELF_DECISION->status())->toBe(403);

    // Pembuat dokumen ≠ pengaju: pembuat tetap ditolak.
    $byCreator = $this->a->submit($this->a->user(), attributes: ['created_by' => $creator->id]);
    expect(aprCode(fn () => aprDecide($creator, $byCreator)))->toBe(ErrorCode::APPROVAL_SELF_DECISION);

    // Delegasi dari pengaju tidak memberi hak.
    $delegate = $this->a->user();
    app(CreateDelegation::class)->handle([
        'delegator_user_id' => $other->id, 'delegate_user_id' => $delegate->id,
        'start_date' => '2026-11-16', 'end_date' => '2026-11-20', 'document_types' => null, 'reason' => 'Dinas luar',
    ], $other->id);
    $byOther = $this->a->submit($other);
    expect(aprCode(fn () => aprDecide($delegate, $byOther)))->toBe(ErrorCode::APPROVAL_SELF_DECISION)
        ->and(aprDecide($creator, $byOther)->status)->toBe(ApprovalStatus::Approved);
});

test('BR-153(11) pengguna yang memutuskan langkah 1 tidak bisa memutuskan langkah 2; langkah 2 tereskalasi bila hanya dia calonnya', function () {
    $this->a->rule([['permission', 'purchase_orders.approve'], ['permission', 'purchase_orders.approve_high']]);
    $both = $this->a->user(permissions: ['purchase_orders.approve', 'purchase_orders.approve_high']);

    $request = aprDecide($both, $this->a->submit($this->a->user()));
    $second = $request->steps()->where('step_order', 2)->first();
    $owner = $this->a->user('owner');

    expect($second->status)->toBe(StepStatus::Pending)
        ->and($second->escalated)->toBeTrue()
        ->and(aprCode(fn () => aprDecide($both, $request)))->toBe(ErrorCode::STATE_CONFLICT)
        ->and(aprDecide($owner, $request)->status)->toBe(ApprovalStatus::Approved);
});

test('SEC-101 dua keputusan pada langkah yang sama: yang kedua (basi) → 409 STATE_CONFLICT, tidak memutuskan langkah berikutnya', function () {
    $this->a->rule([['permission', 'purchase_orders.approve'], ['permission', 'purchase_orders.approve_high']]);
    $a = $this->a->user(permissions: ['purchase_orders.approve']);
    $b = $this->a->user(permissions: ['purchase_orders.approve', 'purchase_orders.approve_high']);
    $request = $this->a->submit($this->a->user());

    aprDecide($a, $request, step: 1);
    // B menekan "Setujui" dari halaman yang masih menampilkan langkah 1.
    expect(aprCode(fn () => aprDecide($b, $request, step: 1)))->toBe(ErrorCode::STATE_CONFLICT)
        ->and($request->refresh()->current_step)->toBe(2)
        ->and($request->steps()->where('step_order', 2)->value('status'))->toBe(StepStatus::Pending);

    $single = $this->a->rule([['permission', 'purchase_orders.approve']], attributes: ['conditions' => ['sales_area_id' => [9]], 'priority' => 1]);
    $one = $this->a->submit($this->a->user(), attributes: ['sales_area_id' => 9]);
    expect($one->rule_snapshot['id'])->toBe($single->id);
    aprDecide($a, $one);
    expect(aprCode(fn () => aprDecide($b, $one, step: 1)))->toBe(ErrorCode::STATE_CONFLICT)
        ->and(ErrorCode::STATE_CONFLICT->status())->toBe(409);
});

test('SEC-101 nominal ≥ approval.reauth_amount wajib konfirmasi password; di bawah ambang tidak', function () {
    setSetting('approval.reauth_amount', '50000000');
    $this->a->rule([['permission', 'purchase_orders.approve']]);
    $approver = $this->a->user(permissions: ['purchase_orders.approve']);
    $requester = $this->a->user();

    $big = $this->a->submit($requester, '50000000');
    try {
        aprDecide($approver, $big, password: false);
        $this->fail('Seharusnya meminta konfirmasi password.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('current_password');
    }
    expect($big->refresh()->status)->toBe(ApprovalStatus::Pending)
        ->and(aprDecide($approver, $big, password: true)->status)->toBe(ApprovalStatus::Approved);

    $small = $this->a->submit($requester, '49999999.99');
    expect(aprDecide($approver, $small, password: false)->status)->toBe(ApprovalStatus::Approved);
});

test('BR-153(3) PA-29 location_head = kepala locations.head_employee_id lokasi dokumen', function () {
    $org = $this->a->org;
    $head = $org->employee();
    $org->gudang->update(['head_employee_id' => $head->id]);
    $headUser = $this->a->user('kepala_lokasi', employee: $head);
    $this->a->rule([['location_head']]);

    $request = $this->a->submit($this->a->user(), attributes: ['scope_location_id' => $org->gudang->id]);

    expect($request->steps[0]->resolved_employee_id)->toBe($head->id)
        ->and(aprDecide($headUser, $request)->status)->toBe(ApprovalStatus::Approved);

    // Lokasi tanpa kepala → langsung tereskalasi (BR-153(5)).
    $noHead = $this->a->submit($this->a->user(), attributes: ['scope_location_id' => $org->toko->id]);
    expect($noHead->steps[0]->escalated)->toBeTrue();
});

test('BR-153(3) izin langkah hanya berlaku bila dokumen dalam scope pemegang izin', function () {
    $org = $this->a->org;
    $staffGudang = $org->employee();
    $kgToko = $this->a->user('kepala_gudang', ['purchase_orders.approve'], $org->employee($org->toko));
    $kgGudang = $this->a->user('kepala_gudang', ['purchase_orders.approve'], $org->employee());
    $this->a->rule([['permission', 'purchase_orders.approve']]);

    $request = $this->a->submit($this->a->user(), attributes: ['scope_employee_id' => $staffGudang->id, 'scope_location_id' => $org->gudang->id]);

    expect(aprCode(fn () => aprDecide($kgToko, $request)))->toBe(ErrorCode::NOT_FOUND)
        ->and(aprDecide($kgGudang, $request)->status)->toBe(ApprovalStatus::Approved);
});

test('ApprovalRule yang dipakai tidak bisa dihapus dari DB (FK) — sumber kebenaran DeactivateApprovalRule', function () {
    $rule = $this->a->rule([['permission', 'purchase_orders.approve']]);
    $this->a->submit($this->a->user());

    expect(fn () => ApprovalRule::query()->whereKey($rule->id)->delete())->toThrow(QueryException::class);
});

test('PA-36 akun tanpa akses web (role pegawai, BR-017) bukan calon penyetuju: langkah atasan langsung langsung tereskalasi', function () {
    $this->useRealPermissions();
    $org = $this->a->org;
    $boss = $org->employee();
    $staff = $org->employee(supervisor: $boss);
    $bossAccount = $this->a->user('pegawai', employee: $boss);
    $this->a->rule([['direct_supervisor']]);
    $request = $this->a->submit($this->a->user(), attributes: ['scope_employee_id' => $staff->id]);

    expect($request->steps[0]->resolved_employee_id)->toBe($boss->id)
        ->and($request->steps[0]->escalated)->toBeTrue()
        ->and(aprCode(fn () => aprDecide($bossAccount, $request)))->not->toBeNull();
    Notification::assertNotSentTo($bossAccount, ApprovalRequestedNotification::class);

    $owner = $this->a->user('owner');
    expect(aprDecide($owner, $request)->status)->toBe(ApprovalStatus::Approved);
});

test('PA-37 Super Admin bukan calon penyetuju dokumen bisnis dan tidak menerima notifikasi approval_*', function () {
    $vendor = $this->a->user('super_admin');
    $this->a->rule([['permission', 'purchase_orders.approve']]);
    $request = $this->a->submit($this->a->user());

    // Hanya Super Admin yang memegang izin → tidak ada calon → tereskalasi; vendor tetap tidak boleh.
    expect($request->steps[0]->escalated)->toBeTrue()
        ->and(aprCode(fn () => aprDecide($vendor, $request)))->toBe(ErrorCode::STATE_CONFLICT);
    Notification::assertNothingSentTo($vendor);

    $owner = $this->a->user('owner');
    expect(aprDecide($owner, $request)->status)->toBe(ApprovalStatus::Approved);
    Notification::assertNothingSentTo($vendor);
});

test('PA-38 pemegang approvals.configure membatalkan permintaan yang sudah diputuskan sebagian dengan alasan wajib; tercatat pembatalan admin', function () {
    $this->a->rule([['permission', 'purchase_orders.approve'], ['permission', 'purchase_orders.approve_high']]);
    $first = $this->a->user(permissions: ['purchase_orders.approve']);
    $requester = $this->a->user();
    $request = aprDecide($first, $this->a->submit($requester));
    $owner = $this->a->user('owner');
    $vendor = $this->a->user('super_admin');
    $cancel = app(CancelApproval::class);

    expect(aprCode(fn () => $cancel->handle($requester, $request->id)))->toBe(ErrorCode::STATE_CONFLICT)
        ->and(aprCode(fn () => $cancel->handle($vendor, $request->id, 'Dibatalkan vendor')))->toBe(ErrorCode::FORBIDDEN)
        ->and(fn () => $cancel->handle($owner, $request->id))->toThrow(ValidationException::class)
        ->and(fn () => $cancel->handle($owner, $request->id, 'abc'))->toThrow(ValidationException::class);

    $done = $cancel->handle($owner, $request->id, 'Penyetuju langkah 2 tidak tersedia');
    expect($done->status)->toBe(ApprovalStatus::Cancelled)
        ->and(TestDocument::query()->find($request->document_id)->status)->toBe('draft');
    $audit = AuditLog::query()->where('event', 'approval_request.cancelled')->sole();
    expect($audit->new_values)->toMatchArray(['status' => 'cancelled', 'reason' => 'admin', 'note' => 'Penyetuju langkah 2 tidak tersedia']);
});
