<?php

namespace App\Domain\Numbering\Enums;

/** Periode reset counter nomor (BR-025); nilai identik dengan ENUM `document_sequences.reset_period`. */
enum ResetPeriod: string
{
    case Never = 'never';
    case Yearly = 'yearly';
    case Monthly = 'monthly';
    case Daily = 'daily';

    public function label(): string
    {
        return match ($this) {
            self::Never => 'Tidak pernah',
            self::Yearly => 'Tahunan',
            self::Monthly => 'Bulanan',
            self::Daily => 'Harian',
        };
    }
}
