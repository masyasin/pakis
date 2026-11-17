<?php

namespace App\Domain\Purchasing\Enums;

/** Status PO (`purchase_orders.status`, 15 §6.6). Nilai identik dengan ENUM DB. */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Closed = 'closed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Submitted => 'Menunggu persetujuan',
            self::Approved => 'Disetujui',
            self::PartiallyReceived => 'Diterima sebagian',
            self::Received => 'Diterima',
            self::Closed => 'Ditutup',
            self::Rejected => 'Ditolak',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /** Status yang masih menunggu barang (filter "terlambat", dasbor PO terlambat). */
    public function isOpen(): bool
    {
        return $this === self::Approved || $this === self::PartiallyReceived;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
