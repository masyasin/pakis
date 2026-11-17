<?php

namespace App\Domain\Pricing\Support;

use App\Domain\Pricing\Data\PromotionData;
use App\Domain\Pricing\Enums\PromotionStatus;
use App\Domain\Pricing\Models\Promotion;
use Carbon\CarbonInterface;

/**
 * Pemuat promo kandidat dari DB untuk PromotionEngine (simulasi, Paket B): promo `active` yang
 * periodenya memuat `at`. Pencocokan kanal/cakupan/target/anggaran tetap di mesin (murni).
 */
final class PromotionCandidates
{
    /** @return array<int, PromotionData> */
    public static function at(CarbonInterface $at): array
    {
        return Promotion::query()->with(['targets', 'scopes', 'tiers'])
            ->where('status', PromotionStatus::Active)
            ->where('starts_at', '<=', $at)->where('ends_at', '>', $at)
            ->orderBy('priority')->orderBy('id')->get()
            ->map(fn (Promotion $p) => PromotionData::fromModel($p))->values()->all();
    }
}
