<?php

namespace Tests\Support;

use App\Domain\Approval\Actions\RequestApproval;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Models\ApprovalRule;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Tests\Fixtures\Approval\TestDocument;

/**
 * Data uji mesin approval (T-067): dua lokasi (EmployeeWorld), dokumen fixture, pembuat aturan, dan
 * pengguna ber-role + izin langsung. Semua data fiktif. Hampir semua pengguna memakai role `purchasing`
 * (scope `all`) supaya yang diuji aturan mesin, bukan scope; uji scope memakai role ber-scope sempit.
 */
final class ApprovalWorld
{
    public EmployeeWorld $org;

    public static function make(TestCase $test): self
    {
        $world = new self;
        $world->org = EmployeeWorld::make($test);
        TestDocument::register();
        // Nominal uji di bawah ambang konfirmasi password, kecuali test SEC-101 reauth.
        setSetting('approval.reauth_amount', '999999999999');

        return $world;
    }

    /**
     * Aturan + langkah. Langkah: `['permission', 'izin']`, `['direct_supervisor']`, `['location_head']`,
     * atau array asosiatif lengkap (escalate_after_hours, escalation_permission).
     *
     * @param  list<array<int|string, mixed>>  $steps
     * @param  array<string, mixed>  $attributes
     */
    public function rule(array $steps, ?string $min = null, ?string $max = null, array $attributes = []): ApprovalRule
    {
        $rule = ApprovalRule::query()->create([
            'document_type' => TestDocument::TYPE,
            'name' => $attributes['name'] ?? 'Aturan uji '.(ApprovalRule::query()->count() + 1),
            'min_amount' => $min,
            'max_amount' => $max,
            'conditions' => $attributes['conditions'] ?? null,
            'priority' => $attributes['priority'] ?? 100,
            'is_active' => $attributes['is_active'] ?? true,
        ]);
        foreach ($steps as $i => $step) {
            $rule->steps()->create([
                'step_order' => $i + 1,
                'approver_type' => $step['approver_type'] ?? $step[0],
                'permission_name' => $step['permission_name'] ?? $step[1] ?? null,
                'escalate_after_hours' => $step['escalate_after_hours'] ?? null,
                'escalation_permission' => $step['escalation_permission'] ?? null,
            ]);
        }

        return $rule->load('steps');
    }

    /** @param  array<string, mixed>  $attributes */
    public function document(?string $amount = '1000000', array $attributes = []): TestDocument
    {
        return TestDocument::query()->create([
            'number' => 'UJI-'.(TestDocument::query()->count() + 1),
            'amount' => $amount,
            'status' => 'submitted',
            ...$attributes,
        ]);
    }

    /** Pengguna ber-role (MFA aktif) + izin langsung tambahan. */
    public function user(string $role = 'purchasing', array $permissions = [], ?Employee $employee = null, array $attributes = []): User
    {
        $user = User::factory()->withTwoFactor()->create(['employee_id' => $employee?->id, ...$attributes])->assignRole($role);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    public function submit(User $requester, ?string $amount = '1000000', array $attributes = []): ?ApprovalRequest
    {
        $document = $this->document($amount, ['created_by' => $requester->id, ...$attributes]);

        return app(RequestApproval::class)->handle($document, $requester);
    }
}
