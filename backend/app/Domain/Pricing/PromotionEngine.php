<?php

namespace App\Domain\Pricing;

use App\Domain\Pricing\Data\AppliedPromotion;
use App\Domain\Pricing\Data\PricedLine;
use App\Domain\Pricing\Data\PromotionContext;
use App\Domain\Pricing\Data\PromotionData;
use App\Domain\Pricing\Data\PromotionResult;
use App\Domain\Pricing\Data\PromotionTierData;
use App\Domain\Pricing\Enums\PromotionType;
use App\Domain\Pricing\Support\PriceRounding;
use App\Domain\Pricing\Support\PromotionLineState;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Mesin promo (BR-169, PA-60). MURNI: tidak membaca DB, setting, atau jam sistem — semua masukan
 * lewat parameter, sehingga hasil sama di simulasi, dokumen jual web (Paket B), dan aplikasi
 * offline Dart (Paket C) yang menjalankan fixture `tests/Fixtures/promotion_cases.json` yang sama.
 *
 * Aturan:
 * 1. Kandidat = periode memuat `at`, kanal cocok, anggaran belum habis, cakupan cocok, ada baris target.
 * 2. Urut `priority` naik lalu id. Promo `is_stackable=false` tidak mengenai baris yang sudah terkena
 *    promo lain dan MENGUNCI baris yang dikenainya dari promo berikutnya.
 * 3. Ambang tier dihitung dari gabungan baris target yang belum terkunci (qty satuan dasar / nominal
 *    setelah promo sebelumnya). Diskon berikutnya dihitung dari harga setelah promo sebelumnya.
 * 4. Diskon %/nominal: harga satuan baru dibulatkan ke atas (`rounding`), tidak melebihi harga lama
 *    dan tidak < 0. Bundel: selisih nilai normal − harga bundel dibagi proporsional, tanpa pembulatan.
 * 5. Beli X gratis Y menambah baris bonus (harga 0) per kelipatan X; baris pembeli tidak berubah harga.
 */
final class PromotionEngine
{
    /**
     * @param  array<int, PricedLine>  $lines
     * @param  array<int, PromotionData>  $promotions
     */
    public function apply(array $lines, PromotionContext $context, array $promotions, string $rounding = 'none'): PromotionResult
    {
        /** @var array<string, PromotionLineState> $state */
        $state = [];
        $subtotal = BigDecimal::zero();
        foreach ($lines as $line) {
            $total = BigDecimal::of($line->unitPrice)->multipliedBy($line->qty)->toScale(2, RoundingMode::HalfUp);
            $state[$line->key] = new PromotionLineState($line, $total);
            $subtotal = $subtotal->plus($total);
        }

        $candidates = array_values(array_filter($promotions, fn (PromotionData $p) => self::eligible($p, $context)));
        usort($candidates, fn (PromotionData $a, PromotionData $b) => [$a->priority, $a->id] <=> [$b->priority, $b->id]);

        $applied = [];
        $bonus = [];
        foreach ($candidates as $promo) {
            $keys = [];
            foreach ($state as $key => $s) {
                if ($s->locked || ! self::targets($promo, $s->line)) {
                    continue;
                }
                if (! $promo->stackable && $s->applied !== []) {
                    continue; // promo tunggal tidak menumpuk di atas promo lain
                }
                $keys[] = $key;
            }
            if ($keys === []) {
                continue;
            }

            $qtyBase = BigDecimal::zero();
            $amount = BigDecimal::zero();
            foreach ($keys as $key) {
                $qtyBase = $qtyBase->plus($state[$key]->qtyBase());
                $amount = $amount->plus($state[$key]->total);
            }
            $tier = self::tier($promo, $qtyBase, $amount);
            if ($tier === null) {
                continue;
            }

            $discounts = match ($promo->type) {
                PromotionType::PercentDiscount, PromotionType::AmountDiscount, PromotionType::TieredDiscount => self::unitDiscounts($state, $keys, $tier, $rounding),
                PromotionType::BundlePrice => self::bundleDiscounts($state, $keys, $tier, $qtyBase),
                PromotionType::BuyXGetY => self::bonus($promo, $tier, $qtyBase, $keys, $bonus),
            };
            if ($discounts === []) {
                continue;
            }

            foreach ($discounts as $key => $discount) {
                $state[$key]->total = $state[$key]->total->minus($discount);
                $state[$key]->applied[] = $promo->code;
                if (! $promo->stackable) {
                    $state[$key]->locked = true;
                }
                $applied[] = new AppliedPromotion($promo->id, $promo->code, $key, (string) $discount->toScale(2));
            }
        }

        $resultLines = [];
        $total = BigDecimal::zero();
        foreach ($state as $key => $s) {
            $original = BigDecimal::of($s->line->unitPrice)->multipliedBy($s->line->qty)->toScale(2, RoundingMode::HalfUp);
            $qty = BigDecimal::of($s->line->qty);
            $resultLines[] = [
                'key' => (string) $key,
                'unit_price' => (string) ($qty->isZero() ? BigDecimal::zero()->toScale(2) : $s->total->dividedBy($qty, 2, RoundingMode::HalfUp)),
                'total' => (string) $s->total->toScale(2),
                'discount' => (string) $original->minus($s->total)->toScale(2),
                'applied' => $s->applied,
            ];
            $total = $total->plus($s->total);
        }

        return new PromotionResult(
            $resultLines, $bonus, $applied,
            (string) $subtotal->toScale(2), (string) $subtotal->minus($total)->toScale(2), (string) $total->toScale(2),
        );
    }

    private static function eligible(PromotionData $p, PromotionContext $c): bool
    {
        if ($c->at->lessThan($p->startsAt) || ! $c->at->lessThan($p->endsAt) || ! in_array($c->channel, $p->channels, true)) {
            return false;
        }
        if ($p->budgetAmount !== null && BigDecimal::of($p->budgetUsed)->isGreaterThanOrEqualTo($p->budgetAmount)) {
            return false;
        }

        foreach ($p->scopes as $scope) {
            $match = match ($scope['type']) {
                'all' => true,
                'price_list' => $c->priceListId !== null && $scope['id'] === $c->priceListId,
                'sales_area' => $c->salesAreaId !== null && $scope['id'] === $c->salesAreaId,
                'customer' => $c->customerId !== null && $scope['id'] === $c->customerId,
                default => false,
            };
            if ($match) {
                return true;
            }
        }

        return false;
    }

    private static function targets(PromotionData $p, PricedLine $line): bool
    {
        foreach ($p->targets as $t) {
            if (($t['type'] === 'product' && $t['id'] === $line->productId)
                || ($t['type'] === 'category' && $t['id'] === $line->categoryId)
                || ($t['type'] === 'principal' && $t['id'] === $line->principalId)) {
                return true;
            }
        }

        return false;
    }

    /** Tier yang berlaku: untuk bertingkat = ambang terbesar yang terpenuhi; lainnya = tier pertama bila terpenuhi. */
    private static function tier(PromotionData $p, BigDecimal $qtyBase, BigDecimal $amount): ?PromotionTierData
    {
        $met = array_values(array_filter($p->tiers, fn (PromotionTierData $t) => ($t->minQtyBase === null || $qtyBase->isGreaterThanOrEqualTo($t->minQtyBase))
            && ($t->minAmount === null || $amount->isGreaterThanOrEqualTo($t->minAmount))));
        if ($met === []) {
            return null;
        }
        if ($p->type !== PromotionType::TieredDiscount) {
            return $p->tiers[0] === $met[0] ? $met[0] : null;
        }

        usort($met, fn (PromotionTierData $a, PromotionTierData $b) => BigDecimal::of($a->minQtyBase ?? '0')->compareTo($b->minQtyBase ?? '0')
            ?: BigDecimal::of($a->minAmount ?? '0')->compareTo($b->minAmount ?? '0'));

        return $met[count($met) - 1];
    }

    /**
     * Diskon %/nominal per baris: harga satuan baru dibulatkan ke atas, dibatasi [0, harga lama].
     *
     * @param  array<string, PromotionLineState>  $state
     * @param  list<string>  $keys
     * @return array<string, BigDecimal>
     */
    private static function unitDiscounts(array $state, array $keys, PromotionTierData $tier, string $rounding): array
    {
        $out = [];
        foreach ($keys as $key) {
            $line = $state[$key]->line;
            $qty = BigDecimal::of($line->qty);
            if ($qty->isZero()) {
                continue;
            }
            $unit = $state[$key]->total->dividedBy($qty, 2, RoundingMode::HalfUp);
            $new = $tier->discountPct !== null
                ? $unit->multipliedBy(BigDecimal::of('100')->minus($tier->discountPct))->dividedBy('100', 2, RoundingMode::HalfUp)
                : $unit->minus(BigDecimal::of((string) $tier->discountAmount)->multipliedBy($line->factor))->toScale(2, RoundingMode::HalfUp);
            if ($new->isNegative()) {
                $new = BigDecimal::zero()->toScale(2);
            }
            $new = PriceRounding::roundUp(Money::of($new), $rounding)->toBigDecimal();
            $newTotal = $new->multipliedBy($qty)->toScale(2, RoundingMode::HalfUp);
            $discount = $state[$key]->total->minus($newTotal);
            if ($discount->isPositive()) {
                $out[$key] = $discount;
            }
        }

        return $out;
    }

    /**
     * Harga bundel: tiap N satuan dasar dibayar `bundle_price`. Satuan dasar diambil berurutan dari
     * baris target; potongan = nilai normal satuan terbundel − bundel × harga, dibagi proporsional
     * (sisa pembagian ke baris terakhir agar jumlahnya tepat).
     *
     * @param  array<string, PromotionLineState>  $state
     * @param  list<string>  $keys
     * @return array<string, BigDecimal>
     */
    private static function bundleDiscounts(array $state, array $keys, PromotionTierData $tier, BigDecimal $qtyBase): array
    {
        $size = BigDecimal::of((string) $tier->minQtyBase);
        if ($size->isZero() || $tier->bundlePrice === null) {
            return [];
        }
        $bundles = $qtyBase->dividedBy($size, 0, RoundingMode::Down);
        $remaining = $bundles->multipliedBy($size);

        $values = [];
        $normal = BigDecimal::zero();
        foreach ($keys as $key) {
            if (! $remaining->isPositive()) {
                break;
            }
            $lineBase = $state[$key]->qtyBase();
            if ($lineBase->isZero()) {
                continue;
            }
            $take = $remaining->isLessThan($lineBase) ? $remaining : $lineBase;
            $value = $state[$key]->total->multipliedBy($take)->dividedBy($lineBase, 2, RoundingMode::HalfUp);
            $values[$key] = $value;
            $normal = $normal->plus($value);
            $remaining = $remaining->minus($take);
        }

        $discount = $normal->minus($bundles->multipliedBy($tier->bundlePrice))->toScale(2);
        if (! $discount->isPositive()) {
            return [];
        }

        $out = [];
        $left = $discount;
        $last = array_key_last($values);
        foreach ($values as $key => $value) {
            $share = $key === $last ? $left : $discount->multipliedBy($value)->dividedBy($normal, 2, RoundingMode::Down);
            $share = $share->isGreaterThan($state[$key]->total) ? $state[$key]->total : $share;
            $out[$key] = $share;
            $left = $left->minus($share);
        }

        return array_filter($out, fn (BigDecimal $d) => $d->isPositive());
    }

    /**
     * Beli X gratis Y: tambah baris bonus; baris pembeli ditandai terkena (potongan 0).
     *
     * @param  list<string>  $keys
     * @param  list<array{promotion_id: int, code: string, product_id: int, qty_base: string}>  $bonus
     * @return array<string, BigDecimal>
     */
    private static function bonus(PromotionData $promo, PromotionTierData $tier, BigDecimal $qtyBase, array $keys, array &$bonus): array
    {
        $x = BigDecimal::of((string) $tier->minQtyBase);
        if ($x->isZero() || $tier->freeProductId === null || $tier->freeQtyBase === null) {
            return [];
        }
        $sets = $qtyBase->dividedBy($x, 0, RoundingMode::Down);
        if (! $sets->isPositive()) {
            return [];
        }
        $bonus[] = [
            'promotion_id' => $promo->id,
            'code' => $promo->code,
            'product_id' => $tier->freeProductId,
            'qty_base' => (string) $sets->multipliedBy($tier->freeQtyBase)->toScale(3),
        ];

        // Potongan 0 tetap dicatat agar baris pembeli terkunci bila promo tidak bisa digabung.
        return array_fill_keys($keys, BigDecimal::zero()->toScale(2));
    }
}
