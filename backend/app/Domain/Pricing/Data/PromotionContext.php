<?php

namespace App\Domain\Pricing\Data;

use Carbon\CarbonImmutable;

/**
 * Konteks penjualan untuk pencocokan promo (BR-169): kios (cakupan `customer`), daftar harganya
 * (`price_list`), areanya (`sales_area`), kanal, dan waktu. Tanpa kios hanya cakupan `all` cocok.
 */
final readonly class PromotionContext
{
    public function __construct(
        public ?int $customerId,
        public ?int $priceListId,
        public ?int $salesAreaId,
        public string $channel,
        public CarbonImmutable $at,
    ) {}
}
