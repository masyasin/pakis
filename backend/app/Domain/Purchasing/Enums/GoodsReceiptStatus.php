<?php

namespace App\Domain\Purchasing\Enums;

/** Status penerimaan barang (`goods_receipts.status`, 15 §6.6). Nilai identik dengan ENUM DB. */
enum GoodsReceiptStatus: string
{
    case Draft = 'draft';
    case Checked = 'checked';
    case PendingApproval = 'pending_approval';
    case Posted = 'posted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Checked => 'Diperiksa',
            self::PendingApproval => 'Menunggu persetujuan',
            self::Posted => 'Diposting',
            self::Rejected => 'Ditolak',
        };
    }

    /** Masih bisa diubah / nomor serinya diisi (sebelum posting). */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Checked;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
