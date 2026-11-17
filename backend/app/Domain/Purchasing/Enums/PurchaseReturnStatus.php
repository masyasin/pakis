<?php

namespace App\Domain\Purchasing\Enums;

/** Status retur ke prinsipal (`purchase_returns.status`, 15 §4.9). Nilai identik dengan ENUM DB. */
enum PurchaseReturnStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Shipped = 'shipped';
    case Closed = 'closed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::PendingApproval => 'Menunggu persetujuan',
            self::Approved => 'Disetujui',
            self::Shipped => 'Dikirim',
            self::Closed => 'Selesai',
            self::Rejected => 'Ditolak',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /** Boleh dibatalkan (PA-68): sebelum diajukan, sesudah disetujui (reservasi dilepas), atau sesudah ditolak. */
    public function isCancellable(): bool
    {
        return in_array($this, [self::Draft, self::Approved, self::Rejected], true);
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
