<?php

namespace App\Domain\Catalog\Queries;

use App\Domain\Catalog\Enums\RegistrationStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductRegistration;
use App\Support\Time\DisplayTime;
use Carbon\CarbonInterface;

/**
 * Izin edar yang berlaku untuk produk pada tanggal tertentu (BR-152): `active` dengan `valid_until`
 * ≥ tanggal itu, yang terjauh. Dipakai FEFO (T-078): produk `requires_registration` tanpa hasil di
 * sini tidak boleh dialokasikan untuk jual/mutasi ke toko.
 *
 * Tidak menunggu job harian: izin yang `valid_until`-nya sudah lewat tetapi belum diubah job menjadi
 * `expired` tetap dianggap tidak berlaku, karena tanggal dibandingkan langsung.
 */
final class ActiveRegistration
{
    public static function for(Product $product, ?CarbonInterface $date = null): ?ProductRegistration
    {
        return ProductRegistration::query()
            ->where('product_id', $product->id)
            ->where('status', RegistrationStatus::Active)
            ->whereDate('valid_until', '>=', self::localDate($date))
            ->orderByDesc('valid_until')->orderByDesc('id')
            ->first();
    }

    /** Tanggal sipil lokal (`general.timezone`) dari instan, default hari ini. */
    public static function localDate(?CarbonInterface $date = null): string
    {
        return DisplayTime::toLocal($date ?? now())->toDateString();
    }
}
