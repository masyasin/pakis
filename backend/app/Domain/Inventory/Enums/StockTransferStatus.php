<?php

namespace App\Domain\Inventory\Enums;

/** Status mutasi antar lokasi (`stock_transfers.status`, 15 §6.6, BR-161). Nilai identik dengan ENUM DB. */
enum StockTransferStatus: string
{
    case Draft = 'draft';
    case Shipped = 'shipped';
    case Received = 'received';
    case ReceivedWithVariance = 'received_with_variance';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Shipped => 'Dikirim',
            self::Received => 'Diterima',
            self::ReceivedWithVariance => 'Diterima dengan selisih',
            self::Closed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /** Barang masih di TRANSIT menunggu penerimaan pertama (pengingat & widget "Mutasi belum diterima"). */
    public function awaitingReceipt(): bool
    {
        return $this === self::Shipped;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
