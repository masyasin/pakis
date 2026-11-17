<?php

use App\Domain\Pricing\Data\PricedLine;
use App\Domain\Pricing\Data\PromotionContext;
use App\Domain\Pricing\Data\PromotionData;
use App\Domain\Pricing\PromotionEngine;
use Carbon\CarbonImmutable;

/*
 * BR-169 / PA-60 — PromotionEngine dijalankan atas fixture lintas platform
 * `tests/Fixtures/promotion_cases.json` (T-076). Mesin murni: test ini tidak butuh aplikasi/DB.
 */

/** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> */
function promotionCases(): array
{
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/promotion_cases.json'), true, flags: JSON_THROW_ON_ERROR);
    $cases = [];
    foreach ($fixture['cases'] as $case) {
        $cases[$case['name']] = [$case, $fixture['defaults']];
    }

    return $cases;
}

test('T-076 fixture promo memuat ≥ 15 kasus dan semua tipe promo', function () {
    $cases = promotionCases();
    $types = collect($cases)->flatMap(fn ($c) => array_column($c[0]['promotions'], 'type'))->unique()->sort()->values()->all();

    expect(count($cases))->toBeGreaterThanOrEqual(15)
        ->and($types)->toBe(['amount_discount', 'bundle_price', 'buy_x_get_y', 'percent_discount', 'tiered_discount']);
});

test('BR-169 PromotionEngine sesuai fixture', function (array $case, array $defaults) {
    $context = [...$defaults['context'], ...($case['context'] ?? [])];
    $promotions = array_map(fn (array $p) => PromotionData::fromArray([...$defaults['promotion'], ...$p]), $case['promotions']);
    $lines = array_map(fn (array $l) => new PricedLine(
        (string) $l['key'], (int) $l['product_id'], (int) $l['category_id'], isset($l['principal_id']) ? (int) $l['principal_id'] : null,
        (string) $l['qty'], (string) $l['factor'], (string) $l['unit_price'],
    ), $case['lines']);

    $result = (new PromotionEngine)->apply(
        $lines,
        new PromotionContext($context['customer_id'], $context['price_list_id'], $context['sales_area_id'], $context['channel'], CarbonImmutable::parse($context['at'])),
        $promotions,
        $case['rounding'] ?? $defaults['rounding'],
    );

    $actualLines = array_map(fn (array $l) => ['key' => $l['key'], 'total' => $l['total'], 'discount' => $l['discount'], 'applied' => $l['applied']], $result->lines);
    $actualBonus = array_map(fn (array $b) => ['code' => $b['code'], 'product_id' => $b['product_id'], 'qty_base' => $b['qty_base']], $result->bonus);

    expect($actualLines)->toBe($case['expected']['lines'])
        ->and($actualBonus)->toBe($case['expected']['bonus'])
        ->and($result->total)->toBe($case['expected']['total']);
})->with(promotionCases());
