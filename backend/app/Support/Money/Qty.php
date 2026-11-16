<?php

namespace App\Support\Money;

use JsonSerializable;
use Stringable;

/** Kuantitas `DECIMAL(18,3)` (ADR-101). Stok selalu dalam satuan dasar (ADR-102). */
final class Qty implements JsonSerializable, Stringable
{
    use DecimalValue;

    private const SCALE = 3;
}
