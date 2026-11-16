<?php

namespace App\Domain\Approval;

use App\Domain\Approval\Models\ApprovalRule;

/**
 * Pemilihan aturan BR-153(1): aktif, jenis dokumen cocok, `min_amount ≤ nominal < max_amount`,
 * `conditions` cocok, lalu `priority` terkecil (seri → id terkecil, agar hasil deterministik).
 *
 * Perbandingan nominal dilakukan MariaDB pada DECIMAL(18,2) (`CAST(? AS DECIMAL)`), bukan float PHP:
 * batas 24.999.999,99 vs 25.000.000 tidak boleh meleset satu sen. Dokumen tanpa nominal hanya cocok
 * dengan aturan tanpa batas nominal.
 */
final class RuleMatcher
{
    /**
     * @param  array<string, int|string|null>  $attributes  nilai `Approvable::approvalConditions()`
     */
    public function match(string $documentType, ?string $amount, array $attributes = []): ?ApprovalRule
    {
        $query = ApprovalRule::query()
            ->with('steps')
            ->where('document_type', $documentType)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id');

        if ($amount === null) {
            $query->whereNull('min_amount')->whereNull('max_amount');
        } else {
            $query->whereRaw('(min_amount IS NULL OR min_amount <= CAST(? AS DECIMAL(18,2)))', [$amount])
                ->whereRaw('(max_amount IS NULL OR max_amount > CAST(? AS DECIMAL(18,2)))', [$amount]);
        }

        foreach ($query->get() as $rule) {
            if ($rule->steps->isNotEmpty() && self::conditionsMatch($rule->conditions, $attributes)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Setiap kunci kondisi wajib ada di dokumen dan nilainya termasuk daftar aturan. Kondisi kosong
     * = berlaku untuk semua dokumen jenis itu.
     *
     * @param  array<string, mixed>|null  $conditions
     * @param  array<string, int|string|null>  $attributes
     */
    public static function conditionsMatch(?array $conditions, array $attributes): bool
    {
        foreach ($conditions ?? [] as $key => $allowed) {
            $value = $attributes[$key] ?? null;
            $allowed = array_map('strval', is_array($allowed) ? $allowed : [$allowed]);
            if ($value === null || ! in_array((string) $value, $allowed, true)) {
                return false;
            }
        }

        return true;
    }
}
