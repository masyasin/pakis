<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Pricing\Enums\PromotionStatus;
use App\Domain\Pricing\Models\Promotion;
use Illuminate\Database\Eloquent\Builder;

/**
 * `EndPromotions` (15 §11.1, tiap 15 menit): promo aktif yang lewat `ends_at` atau anggarannya habis
 * → `ended`. Idempoten (yang sudah berakhir tidak tersentuh lagi). PromotionEngine juga mengabaikan
 * promo di luar periode/anggaran, jadi jeda job tidak memengaruhi harga.
 */
final class EndPromotions
{
    public function __construct(private readonly EndPromotion $end) {}

    public function handle(): int
    {
        $count = 0;
        Promotion::query()->where('status', PromotionStatus::Active)
            ->where(fn (Builder $q) => $q->where('ends_at', '<=', now())
                ->orWhere(fn (Builder $b) => $b->whereNotNull('budget_amount')->whereColumn('budget_used', '>=', 'budget_amount')))
            ->orderBy('id')
            ->each(function (Promotion $promotion) use (&$count) {
                $this->end->handle($promotion, null, $promotion->ends_at->lessThanOrEqualTo(now()) ? 'period_over' : 'budget_exhausted');
                $count++;
            });

        return $count;
    }
}
