<?php

namespace App\Domain\Pricing\Enums;

/** Status promo (`promotions.status`, 15 §6.6). Nilai identik dengan ENUM DB. */
enum PromotionStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::PendingApproval => 'Menunggu persetujuan',
            self::Active => 'Aktif',
            self::Ended => 'Berakhir',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
