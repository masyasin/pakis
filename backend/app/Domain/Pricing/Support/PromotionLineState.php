<?php

namespace App\Domain\Pricing\Support;

use App\Domain\Pricing\Data\PricedLine;
use Brick\Math\BigDecimal;

/**
 * Keadaan satu baris selama PromotionEngine berjalan (internal): total setelah promo sejauh ini,
 * terkunci oleh promo yang tidak bisa digabung, dan kode promo yang sudah mengenainya.
 */
final class PromotionLineState
{
    /** @var list<string> */
    public array $applied = [];

    public bool $locked = false;

    public function __construct(
        public readonly PricedLine $line,
        public BigDecimal $total,
    ) {}

    public function qtyBase(): BigDecimal
    {
        return BigDecimal::of($this->line->qty)->multipliedBy($this->line->factor);
    }
}
