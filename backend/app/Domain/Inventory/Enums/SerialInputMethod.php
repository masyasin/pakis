<?php

namespace App\Domain\Inventory\Enums;

/** Cara nomor seri masuk (`serial_numbers.input_method`). Manual wajib foto label (`serial_manual_chk`). */
enum SerialInputMethod: string
{
    case Scan = 'scan';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Scan => 'Scan',
            self::Manual => 'Manual (foto label)',
        };
    }
}
