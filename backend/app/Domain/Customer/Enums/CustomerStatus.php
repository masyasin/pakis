<?php

namespace App\Domain\Customer\Enums;

/**
 * Status kios (`customers.status`, 15 §4.6, transisi 15 §6.6). Nilai identik dengan ENUM DB.
 * T-073 hanya membuat `prospect`; aktivasi, tahan kredit, nonaktif, dan gabung di T-074/Paket B.
 */
enum CustomerStatus: string
{
    case Prospect = 'prospect';
    case Active = 'active';
    case CreditHold = 'credit_hold';
    case Inactive = 'inactive';
    case Merged = 'merged';

    public function label(): string
    {
        return match ($this) {
            self::Prospect => 'Prospek',
            self::Active => 'Aktif',
            self::CreditHold => 'Tahan kredit',
            self::Inactive => 'Nonaktif',
            self::Merged => 'Digabung',
        };
    }

    /** Kios yang masih bisa dipilih di lookup & dialihkan (BR-006: hanya entri aktif). */
    public function isSelectable(): bool
    {
        return $this !== self::Inactive && $this !== self::Merged;
    }

    /** @return array<string, string> nilai => label, untuk filter daftar */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
