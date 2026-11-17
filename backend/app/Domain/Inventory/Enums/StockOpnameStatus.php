<?php

namespace App\Domain\Inventory\Enums;

/** Status stock opname (`stock_opnames.status`, 15 §6.6, BR-162). Nilai identik dengan ENUM DB. */
enum StockOpnameStatus: string
{
    case Draft = 'draft';
    case Counting = 'counting';
    case Review = 'review';
    case PendingApproval = 'pending_approval';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Counting => 'Sedang dihitung',
            self::Review => 'Tinjau selisih',
            self::PendingApproval => 'Menunggu persetujuan',
            self::Posted => 'Diposting',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /**
     * Batch di opname berstatus ini terkunci dari opname lain (BR-162(8)). `pending_approval` ikut
     * terkunci karena selisihnya belum diposting (PA-67).
     *
     * @return list<string>
     */
    public static function locking(): array
    {
        return [self::Counting->value, self::Review->value, self::PendingApproval->value];
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
