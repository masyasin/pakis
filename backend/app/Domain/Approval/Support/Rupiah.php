<?php

namespace App\Domain\Approval\Support;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Settings\DecimalString;

/**
 * Tampilan rupiah id-ID dari string DECIMAL(18,2) tanpa float (`Rp 24.999.999,99`, `Rp 25.000.000`),
 * dan kalimat alasan aturan untuk kartu kotak masuk (15 §7.1: "Nilai Rp 32.000.000 ≥ Rp 25.000.000").
 */
final class Rupiah
{
    public static function format(?string $amount): ?string
    {
        if ($amount === null || ! DecimalString::isValid($amount)) {
            return null;
        }

        $value = DecimalString::normalize($amount, 2);
        $negative = str_starts_with($value, '-');
        [$int, $frac] = explode('.', ltrim($value, '-'));
        $grouped = strrev(implode('.', str_split(strrev($int), 3)));
        $text = 'Rp '.($negative ? '-' : '').$grouped;

        return $frac === '00' ? $text : $text.','.$frac;
    }

    public static function reason(ApprovalRequest $request): string
    {
        $snapshot = $request->rule_snapshot;
        $min = self::format(isset($snapshot['min_amount']) ? (string) $snapshot['min_amount'] : null);
        $max = self::format(isset($snapshot['max_amount']) ? (string) $snapshot['max_amount'] : null);
        $amount = self::format($request->amount);

        if ($amount === null || ($min === null && $max === null)) {
            return 'Aturan: '.$snapshot['name'];
        }

        $parts = array_filter([$min === null ? null : "≥ {$min}", $max === null ? null : "< {$max}"]);

        return "Nilai {$amount} ".implode(' dan ', $parts);
    }
}
