<?php

use Database\Seeders\ApprovalRuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rantai `customer_credit_limit` ≥ Rp 20 jt = `direct_supervisor` sales kios lalu
 * `credit_limits.set_high` (keputusan Mas 16 Nov 2026, T-074, PA-51; pola PA-26). Bawaan T-067
 * (`credit_limits.set` lalu `credit_limits.set_high`) membuat Owner yang memutus langkah 1 tidak
 * bisa memutus langkah 2 (BR-153(11)).
 *
 * ApprovalRuleSeeder tidak menyentuh jenis yang sudah punya aturan, jadi basis data yang sudah
 * di-seed T-067 diperbarui di sini — HANYA bila kedua aturan masih persis bawaan T-067 dan belum
 * pernah dipakai permintaan apa pun (pola migrasi 060300). Aturan yang sudah diubah dibiarkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rules = DB::table('approval_rules')->where('document_type', 'customer_credit_limit')->orderBy('id')->get();
        if ($rules->count() !== 2) {
            return;
        }

        $expected = [
            'Limit kredit di bawah Rp 20 juta' => [null, 20000000, ['credit_limits.set']],
            'Limit kredit Rp 20 juta ke atas' => [20000000, null, ['credit_limits.set', 'credit_limits.set_high']],
        ];
        foreach ($rules as $rule) {
            $want = $expected[$rule->name] ?? null;
            $steps = DB::table('approval_rule_steps')->where('approval_rule_id', $rule->id)->orderBy('step_order')->get();
            $same = $want !== null
                && ($rule->min_amount === null ? $want[0] === null : $want[0] !== null && (float) $rule->min_amount === (float) $want[0])
                && ($rule->max_amount === null ? $want[1] === null : $want[1] !== null && (float) $rule->max_amount === (float) $want[1])
                && $rule->conditions === null
                && $steps->every(fn ($s) => $s->approver_type === 'permission' && $s->escalation_permission === null)
                && $steps->pluck('permission_name')->all() === $want[2]
                && ! DB::table('approval_requests')->where('approval_rule_id', $rule->id)->exists();
            if (! $same) {
                return;
            }
        }

        DB::transaction(function () use ($rules) {
            $ids = $rules->pluck('id')->all();
            DB::table('approval_rule_steps')->whereIn('approval_rule_id', $ids)->delete();
            DB::table('approval_rules')->whereIn('id', $ids)->delete();
            ApprovalRuleSeeder::seedType('customer_credit_limit');
        });
    }

    public function down(): void
    {
        // Data aturan bisa sudah diubah Owner; tidak dikembalikan otomatis.
    }
};
