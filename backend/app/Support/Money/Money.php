<?php

namespace App\Support\Money;

use JsonSerializable;
use Stringable;

/** Uang rupiah `DECIMAL(18,2)` (ADR-101). */
final class Money implements JsonSerializable, Stringable
{
    use DecimalValue;

    private const SCALE = 2;
}
