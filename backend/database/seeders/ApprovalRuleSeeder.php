<?php

namespace Database\Seeders;

use App\Domain\Approval\Models\ApprovalRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Aturan approval bawaan 15 §4.2.1 (T-067; aktivasi kios bertingkat T-074, PA-51), termasuk PA-26: PO ≥ Rp 25 jt = atasan langsung pengaju
 * lalu `purchase_orders.approve_high` (BR-153(11): satu orang satu langkah, jadi dua langkah yang
 * sama-sama hanya dipegang Owner tidak bisa dipakai). Angka rupiah adalah CONTOH (OQ-113), ditetapkan
 * Pakis saat UAT lewat layar Aturan Persetujuan.
 *
 * Idempoten & menghormati ubahan Owner: jenis dokumen yang sudah punya aturan apa pun (aktif atau
 * nonaktif) tidak disentuh, jadi aturan yang diubah/dinonaktifkan tidak dikembalikan. Hanya jenis yang
 * sama sekali tanpa aturan yang diisi ulang (lebih aman daripada dokumen tanpa aturan → RULE_MISSING).
 * Data sistem, bukan demo: dijalankan juga di produksi.
 */
class ApprovalRuleSeeder extends Seeder
{
    /**
     * @return array<string, list<array{name: string, min: string|null, max: string|null, steps: list<array{0: string, 1?: string|null, 2?: string|null}>}>>
     */
    public static function defaults(): array
    {
        $perm = fn (string $permission): array => ['permission', $permission];

        return [
            'purchase_order' => [
                ['name' => 'PO di bawah Rp 25 juta', 'min' => null, 'max' => '25000000', 'steps' => [$perm('purchase_orders.approve')]],
                ['name' => 'PO Rp 25 juta ke atas', 'min' => '25000000', 'max' => null, 'steps' => [['direct_supervisor'], $perm('purchase_orders.approve_high')]],
            ],
            'goods_receipt_short_shelf_life' => [
                ['name' => 'Penerimaan sisa umur pendek', 'min' => null, 'max' => null, 'steps' => [['location_head', null, 'goods_receipts.approve_short_shelf_life']]],
            ],
            'stock_transfer_variance' => [
                ['name' => 'Selisih mutasi', 'min' => null, 'max' => null, 'steps' => [$perm('stock_transfers.approve_variance')]],
            ],
            'stock_opname_variance' => [
                ['name' => 'Selisih opname di bawah Rp 1 juta', 'min' => null, 'max' => '1000000', 'steps' => [$perm('stock_opnames.approve_variance')]],
                ['name' => 'Selisih opname Rp 1 juta ke atas', 'min' => '1000000', 'max' => null, 'steps' => [$perm('stock_opnames.approve_variance'), $perm('stock_opnames.approve_variance_high')]],
            ],
            'stock_disposal' => [
                ['name' => 'Pemusnahan stok', 'min' => null, 'max' => null, 'steps' => [$perm('stock_disposals.approve')]],
            ],
            'stock_quarantine_release' => [
                ['name' => 'Lepas karantina', 'min' => null, 'max' => null, 'steps' => [$perm('stock_quarantine.release')]],
            ],
            'purchase_return' => [
                ['name' => 'Retur ke prinsipal', 'min' => null, 'max' => null, 'steps' => [$perm('purchase_returns.approve')]],
            ],
            // Keputusan Mas 16 Nov 2026 (T-074): satu permintaan, rantai bertingkat menurut limit yang
            // diminta. Langkah ≥ 20 jt dipegang `credit_limits.set_high`, BUKAN izin yang sama dua kali,
            // agar BR-153(11) (satu orang satu langkah) tidak membuat langkah 2 buntu.
            'customer_activation' => [
                ['name' => 'Aktivasi kios, limit di bawah Rp 20 juta', 'min' => null, 'max' => '20000000', 'steps' => [$perm('customers.verify')]],
                ['name' => 'Aktivasi kios, limit Rp 20 juta ke atas', 'min' => '20000000', 'max' => null, 'steps' => [$perm('customers.verify'), $perm('credit_limits.set_high')]],
            ],
            'customer_credit_limit' => [
                ['name' => 'Limit kredit di bawah Rp 20 juta', 'min' => null, 'max' => '20000000', 'steps' => [$perm('credit_limits.set')]],
                ['name' => 'Limit kredit Rp 20 juta ke atas', 'min' => '20000000', 'max' => null, 'steps' => [$perm('credit_limits.set'), $perm('credit_limits.set_high')]],
            ],
            'customer_phone_change' => [
                ['name' => 'Ganti nomor HP kios', 'min' => null, 'max' => null, 'steps' => [$perm('customers.change_phone_approve')]],
            ],
            'customer_merge' => [
                ['name' => 'Gabung kios duplikat', 'min' => null, 'max' => null, 'steps' => [$perm('customers.merge')]],
            ],
            'price_list_publish' => [
                ['name' => 'Terbit daftar harga', 'min' => null, 'max' => null, 'steps' => [$perm('prices.publish')]],
            ],
            'promotion_activate' => [
                ['name' => 'Aktivasi promo', 'min' => null, 'max' => null, 'steps' => [$perm('promotions.approve')]],
            ],
            'serial_swap' => [
                ['name' => 'Tukar nomor seri', 'min' => null, 'max' => null, 'steps' => [$perm('serials.approve_swap')]],
            ],
        ];
    }

    public function run(): void
    {
        DB::transaction(function () {
            foreach (array_keys(self::defaults()) as $type) {
                if (! ApprovalRule::query()->where('document_type', $type)->exists()) {
                    self::seedType($type);
                }
            }
        });
    }

    /** Buat aturan bawaan satu jenis dokumen (juga dipakai migrasi data aturan bertingkat T-074). */
    public static function seedType(string $type): void
    {
        foreach (self::defaults()[$type] ?? [] as $definition) {
            $rule = ApprovalRule::query()->create([
                'document_type' => $type,
                'name' => $definition['name'],
                'min_amount' => $definition['min'],
                'max_amount' => $definition['max'],
                'conditions' => null,
                'priority' => 100,
                'is_active' => true,
            ]);
            foreach ($definition['steps'] as $i => $step) {
                $rule->steps()->create([
                    'step_order' => $i + 1,
                    'approver_type' => $step[0],
                    'permission_name' => $step[0] === 'permission' ? ($step[1] ?? null) : null,
                    'escalate_after_hours' => null,
                    'escalation_permission' => $step[2] ?? null,
                ]);
            }
        }
    }
}
