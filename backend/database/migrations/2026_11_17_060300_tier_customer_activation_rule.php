<?php

use Database\Seeders\ApprovalRuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aturan `customer_activation` menjadi bertingkat (keputusan Mas 16 Nov 2026, T-074, PA-51):
 * < Rp 20 jt `customers.verify`; ≥ Rp 20 jt `customers.verify` lalu `credit_limits.set_high`.
 *
 * ApprovalRuleSeeder tidak menyentuh jenis dokumen yang sudah punya aturan (menghormati ubahan
 * Owner), jadi basis data yang sudah di-seed T-067 perlu migrasi data ini. Hanya bertindak bila
 * aturannya MASIH persis bawaan T-067 (satu aturan tanpa rentang, satu langkah `customers.verify`)
 * dan belum pernah dipakai permintaan apa pun; aturan yang sudah diubah/dipakai dibiarkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rules = DB::table('approval_rules')->where('document_type', 'customer_activation')->get();
        if ($rules->count() !== 1) {
            return;
        }
        $rule = $rules->first();
        $steps = DB::table('approval_rule_steps')->where('approval_rule_id', $rule->id)->get();
        $untouched = $rule->name === 'Aktivasi kios'
            && $rule->min_amount === null && $rule->max_amount === null && $rule->conditions === null
            && $steps->count() === 1
            && $steps->first()->approver_type === 'permission'
            && $steps->first()->permission_name === 'customers.verify'
            && ! DB::table('approval_requests')->where('approval_rule_id', $rule->id)->exists();
        if (! $untouched) {
            return;
        }

        DB::transaction(function () use ($rule) {
            DB::table('approval_rule_steps')->where('approval_rule_id', $rule->id)->delete();
            DB::table('approval_rules')->where('id', $rule->id)->delete();
            ApprovalRuleSeeder::seedType('customer_activation');
        });
    }

    public function down(): void
    {
        // Data aturan bisa sudah diubah Owner; tidak dikembalikan otomatis.
    }
};
