<?php

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Enums\RegistrationStatus;
use App\Domain\Catalog\Queries\ActiveRegistration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Status izin tampilan per produk (BR-152, 15 §7.3 filter `registration_status`):
 * - `active`   : ada izin berlaku dan habisnya lebih dari `inventory.registration_reminder_days` lagi
 * - `expiring` : ada izin berlaku, terjauh habis ≤ hari ini + ambang ("akan habis")
 * - `expired`  : tidak ada izin berlaku, tetapi pernah punya izin (habis/dicabut)
 * - `none`     : belum pernah punya izin sama sekali
 * Semua dihitung dari tanggal, bukan kolom status saja, agar tidak bergantung pada jam job harian.
 */
final class RegistrationState
{
    public const STATES = ['active', 'expiring', 'expired', 'none'];

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (string $s) => ['value' => $s, 'label' => self::label($s)], self::STATES);
    }

    public static function label(string $state): string
    {
        return match ($state) {
            'active' => 'Izin berlaku',
            'expiring' => 'Izin akan habis',
            'expired' => 'Izin habis',
            default => 'Belum ada izin',
        };
    }

    public static function thresholdDays(): int
    {
        return (int) setting('inventory.registration_reminder_days');
    }

    /** Batas akhir "akan habis": hari ini + ambang (tanggal lokal). */
    public static function expiringUntil(): string
    {
        return CarbonImmutable::parse(ActiveRegistration::localDate())->addDays(self::thresholdDays())->toDateString();
    }

    /**
     * Saring produk menurut status izin tampilan.
     *
     * @param  Builder<*>  $query  query `products`
     */
    public static function apply(Builder $query, string $state): void
    {
        $today = ActiveRegistration::localDate();
        $until = self::expiringUntil();
        // Izin berlaku terjauh per produk (subquery, satu index `preg_product_valid_idx`).
        $valid = fn (QueryBuilder $q) => $q->from('product_registrations')
            ->whereColumn('product_registrations.product_id', 'products.id')
            ->where('product_registrations.status', RegistrationStatus::Active->value)
            ->where('product_registrations.valid_until', '>=', $today);

        match ($state) {
            'active' => $query->whereExists(fn (QueryBuilder $q) => $valid($q)->where('product_registrations.valid_until', '>', $until)),
            'expiring' => $query->whereExists($valid)
                ->whereNotExists(fn (QueryBuilder $q) => $valid($q)->where('product_registrations.valid_until', '>', $until)),
            'expired' => $query->whereNotExists($valid)
                ->whereExists(fn (QueryBuilder $q) => $q->from('product_registrations')->whereColumn('product_registrations.product_id', 'products.id')),
            default => $query->whereNotExists(fn (QueryBuilder $q) => $q->from('product_registrations')->whereColumn('product_registrations.product_id', 'products.id')),
        };
    }

    /** Status satu produk dari izin berlaku terjauh (null bila tidak ada) dan apakah pernah punya izin. */
    public static function of(?string $validUntil, bool $hasAny): string
    {
        if ($validUntil === null) {
            return $hasAny ? 'expired' : 'none';
        }

        return $validUntil <= self::expiringUntil() ? 'expiring' : 'active';
    }
}
