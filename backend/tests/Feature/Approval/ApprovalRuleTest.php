<?php

use App\Domain\Approval\Models\ApprovalRule;
use App\Domain\Audit\Models\AuditLog;
use Carbon\CarbonImmutable;
use Database\Seeders\ApprovalRuleSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Approval\TestDocument;
use Tests\Support\ApprovalWorld;

/*
 * T-067 — Aturan Persetujuan (FR-A02, 15 §5, SEC-110) & seed aturan bawaan (15 §4.2.1, PA-26).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Jakarta'));
    Notification::fake();
    $this->a = ApprovalWorld::make($this);
    $this->useRealPermissions();
    $this->owner = $this->a->user('owner');
});

/** @return array<string, mixed> */
function aprRulePayload(array $override = []): array
{
    return array_replace([
        'document_type' => TestDocument::TYPE,
        'name' => 'PO besar',
        'min_amount' => '25000000',
        'max_amount' => '',
        'priority' => 100,
        'is_active' => true,
        'steps' => [
            ['approver_type' => 'direct_supervisor', 'permission_name' => '', 'escalate_after_hours' => '', 'escalation_permission' => ''],
            ['approver_type' => 'permission', 'permission_name' => 'purchase_orders.approve_high', 'escalate_after_hours' => 48, 'escalation_permission' => ''],
        ],
    ], $override);
}

test('SEC-110 FR-A02 buat aturan: wajib konfirmasi password, tersimpan dengan langkah berurutan, audit approval_rule.created', function () {
    $this->actingAs($this->owner)->get('/pengaturan/aturan-persetujuan/baru')->assertRedirect(route('password.confirm'));

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
        ->get('/pengaturan/aturan-persetujuan/baru')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('site-settings/approval-rules/form', false)
            ->where('rule', null)->has('documentTypes')->has('approverTypes', 3)->has('permissions'));

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
        ->post('/pengaturan/aturan-persetujuan', aprRulePayload())
        ->assertRedirect('/pengaturan/aturan-persetujuan');

    $rule = ApprovalRule::query()->with('steps')->sole();
    expect($rule->min_amount)->toBe('25000000.00')
        ->and($rule->max_amount)->toBeNull()
        ->and($rule->steps->pluck('approver_type')->map->value->all())->toBe(['direct_supervisor', 'permission'])
        ->and($rule->steps[1]->escalate_after_hours)->toBe(48)
        ->and($rule->steps[0]->permission_name)->toBeNull();
    $audit = AuditLog::query()->where('event', 'approval_rule.created')->sole();
    expect($audit->new_values['steps'])->toHaveCount(2);
});

test('FR-A02 validasi aturan: batas atas > bawah, izin langkah wajib & ada di katalog, kondisi sesuai jenis dokumen', function () {
    $session = ['auth.password_confirmed_at' => time()];
    $post = fn (array $override) => $this->actingAs($this->owner)->withSession($session)
        ->from('/pengaturan/aturan-persetujuan/baru')->post('/pengaturan/aturan-persetujuan', aprRulePayload($override));

    $post(['min_amount' => '25000000', 'max_amount' => '25000000'])->assertSessionHasErrors('max_amount');
    $post(['min_amount' => '1.234'])->assertSessionHasErrors('min_amount');
    $post(['steps' => [['approver_type' => 'permission', 'permission_name' => '']]])->assertSessionHasErrors('steps.0.permission_name');
    $post(['steps' => [['approver_type' => 'permission', 'permission_name' => 'tidak.ada']]])->assertSessionHasErrors('steps.0.permission_name');
    $post(['steps' => []])->assertSessionHasErrors('steps');
    $post(['conditions' => ['supplier_id' => [1]]])->assertSessionHasErrors('conditions');
    $post(['document_type' => 'tidak_dikenal'])->assertSessionHasErrors('document_type');
    $post(['conditions' => ['sales_area_id' => [3, 4]]])->assertSessionHasNoErrors();

    expect(ApprovalRule::query()->count())->toBe(1);
});

test('SEC-110 ubah aturan tercatat audit lama/baru; hapus aturan belum dipakai = dihapus, sudah dipakai = dinonaktifkan', function () {
    $session = ['auth.password_confirmed_at' => time()];
    $unused = $this->a->rule([['permission', 'purchase_orders.approve']], attributes: ['name' => 'Belum dipakai', 'conditions' => ['sales_area_id' => [7]]]);
    $used = $this->a->rule([['permission', 'purchase_orders.approve']], attributes: ['name' => 'Sudah dipakai']);
    $this->a->submit($this->a->user());

    $this->actingAs($this->owner)->withSession($session)->get("/pengaturan/aturan-persetujuan/{$used->id}/ubah")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('rule.id', $used->id)->where('rule.used', true)->has('rule.steps', 1));

    $this->actingAs($this->owner)->withSession($session)
        ->put("/pengaturan/aturan-persetujuan/{$used->id}", aprRulePayload(['name' => 'Sudah dipakai v2', 'min_amount' => '', 'max_amount' => '']))
        ->assertRedirect('/pengaturan/aturan-persetujuan');
    $audit = AuditLog::query()->where('event', 'approval_rule.updated')->sole();
    expect($audit->old_values['name'])->toBe('Sudah dipakai')->and($audit->new_values['name'])->toBe('Sudah dipakai v2');

    $this->actingAs($this->owner)->withSession($session)->delete("/pengaturan/aturan-persetujuan/{$used->id}")->assertRedirect();
    $this->actingAs($this->owner)->withSession($session)->delete("/pengaturan/aturan-persetujuan/{$unused->id}")->assertRedirect();

    expect($used->refresh()->is_active)->toBeFalse()
        ->and(ApprovalRule::query()->whereKey($unused->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'approval_rule.deactivated')->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'approval_rule.deleted')->count())->toBe(1);
});

test('approvals.configure wajib untuk semua rute aturan (403), termasuk pratinjau', function () {
    $rule = $this->a->rule([['permission', 'purchase_orders.approve']]);
    $purchasing = $this->a->user('purchasing');
    $session = ['auth.password_confirmed_at' => time()];

    $this->actingAs($purchasing)->get('/pengaturan/aturan-persetujuan')->assertForbidden();
    $this->actingAs($purchasing)->withSession($session)->get("/pengaturan/aturan-persetujuan/{$rule->id}/ubah")->assertForbidden();
    $this->actingAs($purchasing)->withSession($session)->put("/pengaturan/aturan-persetujuan/{$rule->id}", aprRulePayload())->assertForbidden();
    $this->actingAs($purchasing)->withSession($session)->delete("/pengaturan/aturan-persetujuan/{$rule->id}")->assertForbidden();
    $this->actingAs($purchasing)->postJson('/pengaturan/aturan-persetujuan/pratinjau', ['document_type' => TestDocument::TYPE])->assertForbidden();
    expect($rule->refresh()->is_active)->toBeTrue();
});

test('BR-001 listing approval_rules: cari nama, filter jenis & aktif, sort prioritas', function () {
    $this->a->rule([['permission', 'purchase_orders.approve']], attributes: ['name' => 'Alpha', 'priority' => 20]);
    $this->a->rule([['permission', 'purchase_orders.approve']], attributes: ['name' => 'Beta', 'priority' => 10, 'is_active' => false]);

    $this->actingAs($this->owner)->get('/pengaturan/aturan-persetujuan?q=alp')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('site-settings/approval-rules/index', false)->has('rows', 1)->where('rows.0.name', 'Alpha'));
    $this->actingAs($this->owner)->get('/pengaturan/aturan-persetujuan?filter[is_active]=0')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.name', 'Beta'));
    $this->actingAs($this->owner)->get('/pengaturan/aturan-persetujuan?filter[document_type]=uji_dokumen&sort=priority')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('rows', 2)->where('rows.0.name', 'Beta')->where('rows.0.used', false));
});

test('FR-A02 pratinjau rantai memakai pemilihan aturan yang sama (batas inklusif/eksklusif) + RULE_MISSING', function () {
    $this->a->rule([['permission', 'purchase_orders.approve']], max: '25000000', attributes: ['name' => 'Kecil']);
    $this->a->rule([['direct_supervisor'], ['permission', 'purchase_orders.approve_high']], min: '25000000', attributes: ['name' => 'Besar']);
    $preview = fn (?string $amount) => $this->actingAs($this->owner)
        ->postJson('/pengaturan/aturan-persetujuan/pratinjau', ['document_type' => TestDocument::TYPE, 'amount' => $amount]);

    $preview('24999999.99')->assertOk()->assertJsonPath('data.rule.name', 'Kecil')->assertJsonCount(1, 'data.steps')
        ->assertJsonPath('data.steps.0.holders_count', 1);
    $preview('25000000')->assertOk()->assertJsonPath('data.rule.name', 'Besar')
        ->assertJsonPath('data.steps.0.approver_type', 'direct_supervisor')
        ->assertJsonPath('data.steps.1.approver_type', 'permission');

    setSetting('approval.auto_approve_when_no_rule', false);
    $preview(null)->assertOk()->assertJsonPath('data.rule', null)->assertJsonPath('data.missing', true);
});

test('15 §4.2.1 PA-26 PA-51 ApprovalRuleSeeder: 18 aturan bawaan untuk 14 jenis, PO ≥ 25 jt = atasan langsung lalu approve_high; idempoten & hormati ubahan', function () {
    $this->seed(ApprovalRuleSeeder::class);

    expect(ApprovalRule::query()->count())->toBe(18)
        ->and(ApprovalRule::query()->distinct()->count('document_type'))->toBe(14);
    $big = ApprovalRule::query()->with('steps')->where('document_type', 'purchase_order')->whereNotNull('min_amount')->sole();
    expect($big->min_amount)->toBe('25000000.00')
        ->and($big->steps->map(fn ($s) => [$s->approver_type->value, $s->permission_name])->all())
        ->toBe([['direct_supervisor', null], ['permission', 'purchase_orders.approve_high']]);
    $short = ApprovalRule::query()->with('steps')->where('document_type', 'goods_receipt_short_shelf_life')->sole();
    expect($short->steps[0]->escalation_permission)->toBe('goods_receipts.approve_short_shelf_life');

    // Seed ulang tidak menggandakan & tidak menimpa ubahan; jenis yang kosong sama sekali diisi lagi.
    ApprovalRule::query()->where('document_type', 'serial_swap')->delete();
    ApprovalRule::query()->where('document_type', 'stock_disposal')->update(['name' => 'Diubah Owner']);
    $this->seed(ApprovalRuleSeeder::class);
    expect(ApprovalRule::query()->count())->toBe(18)
        ->and(ApprovalRule::query()->where('document_type', 'stock_disposal')->value('name'))->toBe('Diubah Owner');
});
