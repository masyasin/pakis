<?php

namespace App\Domain\Pricing\Data;

use App\Domain\Pricing\Enums\PromotionType;
use App\Domain\Pricing\Models\Promotion;
use Carbon\CarbonImmutable;

/**
 * Bentuk promo yang dibaca PromotionEngine (BR-169): murni data, tanpa Eloquent, agar bisa dibuat
 * dari DB (`fromModel`) maupun dari fixture JSON lintas platform (`fromArray`, dipakai juga Dart di
 * Paket C). Angka berupa string desimal (ADR-101).
 */
final readonly class PromotionData
{
    /**
     * @param  array<int, string>  $channels
     * @param  array<int, array{type: string, id: int}>  $targets
     * @param  array<int, array{type: string, id: int|null}>  $scopes
     * @param  array<int, PromotionTierData>  $tiers
     */
    public function __construct(
        public int $id,
        public string $code,
        public PromotionType $type,
        public int $priority,
        public bool $stackable,
        public array $channels,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public ?string $budgetAmount,
        public string $budgetUsed,
        public array $targets,
        public array $scopes,
        public array $tiers,
    ) {}

    public static function fromModel(Promotion $p): self
    {
        $p->loadMissing(['targets', 'scopes', 'tiers']);

        return new self(
            $p->id, $p->code, $p->type, $p->priority, $p->is_stackable, $p->channels,
            CarbonImmutable::instance($p->starts_at), CarbonImmutable::instance($p->ends_at),
            $p->budget_amount, (string) $p->budget_used,
            $p->targets->map(fn ($t) => ['type' => $t->target_type, 'id' => $t->target_id])->values()->all(),
            $p->scopes->map(fn ($s) => ['type' => $s->scope_type, 'id' => $s->scope_id])->values()->all(),
            $p->tiers->map(fn ($t) => new PromotionTierData(
                $t->min_qty_base, $t->min_amount, $t->discount_pct, $t->discount_amount, $t->bundle_price, $t->free_product_id, $t->free_qty_base,
            ))->values()->all(),
        );
    }

    /** @param  array<string, mixed>  $a  bentuk fixture `promotion_cases.json` */
    public static function fromArray(array $a): self
    {
        return new self(
            (int) $a['id'], (string) $a['code'], PromotionType::from((string) $a['type']), (int) ($a['priority'] ?? 100),
            (bool) ($a['is_stackable'] ?? false), array_values($a['channels'] ?? []),
            CarbonImmutable::parse((string) $a['starts_at']), CarbonImmutable::parse((string) $a['ends_at']),
            isset($a['budget_amount']) ? (string) $a['budget_amount'] : null, (string) ($a['budget_used'] ?? '0'),
            array_map(fn (array $t) => ['type' => (string) $t['type'], 'id' => (int) $t['id']], $a['targets'] ?? []),
            array_map(fn (array $s) => ['type' => (string) $s['type'], 'id' => isset($s['id']) ? (int) $s['id'] : null], $a['scopes'] ?? []),
            array_map(fn (array $t) => new PromotionTierData(
                isset($t['min_qty_base']) ? (string) $t['min_qty_base'] : null,
                isset($t['min_amount']) ? (string) $t['min_amount'] : null,
                isset($t['discount_pct']) ? (string) $t['discount_pct'] : null,
                isset($t['discount_amount']) ? (string) $t['discount_amount'] : null,
                isset($t['bundle_price']) ? (string) $t['bundle_price'] : null,
                isset($t['free_product_id']) ? (int) $t['free_product_id'] : null,
                isset($t['free_qty_base']) ? (string) $t['free_qty_base'] : null,
            ), $a['tiers'] ?? []),
        );
    }
}
