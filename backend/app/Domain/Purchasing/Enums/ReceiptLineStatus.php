<?php

namespace App\Domain\Purchasing\Enums;

/**
 * Hasil pemeriksaan baris penerimaan (`goods_receipt_lines.line_status`, BR-103, BR-158(4)). Nilai
 * identik dengan ENUM DB. `short_shelf_life` → approval; `registration_mismatch` → bin karantina.
 */
enum ReceiptLineStatus: string
{
    case Ok = 'ok';
    case ShortShelfLife = 'short_shelf_life';
    case RegistrationMismatch = 'registration_mismatch';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Sesuai',
            self::ShortShelfLife => 'Sisa umur pendek',
            self::RegistrationMismatch => 'Izin edar tidak cocok',
        };
    }
}
