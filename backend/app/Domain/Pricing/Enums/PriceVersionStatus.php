<?php

namespace App\Domain\Pricing\Enums;

/** Status versi daftar harga (`price_list_versions.status`, 15 §6.6). Nilai identik dengan ENUM DB. */
enum PriceVersionStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Published = 'published';
    case Superseded = 'superseded';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::PendingApproval => 'Menunggu persetujuan',
            self::Published => 'Terbit',
            self::Superseded => 'Digantikan',
            self::Rejected => 'Ditolak',
        };
    }

    /** Versi yang ikut dipakai resolusi harga (dibatasi rentang berlaku). */
    public function isEffective(): bool
    {
        return $this === self::Published || $this === self::Superseded;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
