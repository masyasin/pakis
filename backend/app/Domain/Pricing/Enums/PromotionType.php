<?php

namespace App\Domain\Pricing\Enums;

/** Tipe promo (`promotions.type`, 15 §4.7, FR-A20). Nilai identik dengan ENUM DB. */
enum PromotionType: string
{
    case PercentDiscount = 'percent_discount';
    case AmountDiscount = 'amount_discount';
    case BuyXGetY = 'buy_x_get_y';
    case BundlePrice = 'bundle_price';
    case TieredDiscount = 'tiered_discount';

    public function label(): string
    {
        return match ($this) {
            self::PercentDiscount => 'Diskon persen',
            self::AmountDiscount => 'Diskon nominal',
            self::BuyXGetY => 'Beli X gratis Y',
            self::BundlePrice => 'Harga bundel',
            self::TieredDiscount => 'Diskon bertingkat',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
