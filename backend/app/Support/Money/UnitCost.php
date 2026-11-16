<?php

namespace App\Support\Money;

use JsonSerializable;
use Stringable;

/**
 * Harga/biaya per satuan dasar `DECIMAL(18,4)` (ADR-101): HPP per botol dari harga per dus bisa
 * berdesimal panjang, jadi skalanya lebih besar dari uang.
 */
final class UnitCost implements JsonSerializable, Stringable
{
    use DecimalValue;

    private const SCALE = 4;
}
