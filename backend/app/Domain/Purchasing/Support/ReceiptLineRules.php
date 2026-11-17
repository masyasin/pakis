<?php

namespace App\Domain\Purchasing\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductRegistration;
use App\Domain\Purchasing\Enums\ReceiptLineStatus;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Aturan murni pemeriksaan baris penerimaan (BR-103, BR-158(4)); tanpa DB agar mudah diuji unit.
 *
 * - Sisa umur = bulan penuh antara tanggal terima dan ED + sisa hari dibagi jumlah hari bulan berjalan,
 *   1 desimal DIBULATKAN KE BAWAH (menuju nol), tanpa float (BigDecimal). Half-up akan membuat 5 bulan
 *   29 hari menjadi 6,0 dan lolos batas 6 bulan; sisa umur tidak boleh dilebihkan.
 * - Urutan putusan: ED ≤ tanggal terima → `BATCH_EXPIRED_ON_RECEIPT` (tidak bisa disetujui siapa pun);
 *   izin edar tidak cocok → `registration_mismatch` (bin karantina, bukan ditolak); sisa umur < batas
 *   → `short_shelf_life` (approval); selain itu `ok`.
 * - Batas = `products.min_shelf_life_months` ?? `inventory.min_shelf_life_months`; 0 = tanpa batas.
 */
final class ReceiptLineRules
{
    /**
     * Sisa umur dalam bulan (1 desimal) dari tanggal terima lokal ke ED; null bila tanpa ED. Negatif bila
     * ED sudah lewat (pemanggil menolak lewat `assertNotExpired`).
     */
    public static function shelfLifeMonths(string $receivedDate, ?string $expiryDate): ?string
    {
        if ($expiryDate === null) {
            return null;
        }
        $from = CarbonImmutable::parse($receivedDate)->startOfDay();
        $to = CarbonImmutable::parse($expiryDate)->startOfDay();
        $sign = $to->lessThan($from) ? -1 : 1;
        [$a, $b] = $sign < 0 ? [$to, $from] : [$from, $to];

        $months = $a->diffInMonths($b);
        $anchor = $a->addMonths((int) $months);
        $days = $anchor->diffInDays($b);
        // Sisa hari dibandingkan panjang bulan yang sedang berjalan dari titik jangkar.
        $daysInMonth = $anchor->daysInMonth;

        $value = BigDecimal::of((int) $months)
            ->plus(BigDecimal::of((int) $days)->dividedBy($daysInMonth, 6, RoundingMode::HalfUp))
            ->toScale(1, RoundingMode::Down);

        return (string) ($sign < 0 ? $value->negated() : $value);
    }

    /** @throws ApiError BATCH_EXPIRED_ON_RECEIPT bila ED ≤ tanggal terima (BR-103) */
    public static function assertNotExpired(string $receivedDate, ?string $expiryDate, string $field = 'expiry_date'): void
    {
        if ($expiryDate !== null && $expiryDate <= $receivedDate) {
            throw new ApiError(ErrorCode::BATCH_EXPIRED_ON_RECEIPT, [$field => ErrorCode::BATCH_EXPIRED_ON_RECEIPT->message()]);
        }
    }

    /** Nomor izin dibandingkan tanpa peduli huruf besar/kecil dan spasi (ketikan dari kemasan). */
    public static function normalizeRegistration(?string $no): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', (string) $no));
    }

    /**
     * Status baris. `$active` = izin edar aktif produk pada tanggal terima (ActiveRegistration::for),
     * null bila tidak ada. `$defaultMinMonths` = `inventory.min_shelf_life_months`.
     */
    public static function status(Product $product, ?string $shelfLifeMonths, ?string $registrationSeen, ?ProductRegistration $active, int $defaultMinMonths): ReceiptLineStatus
    {
        if ($product->requires_registration) {
            if ($active === null || self::normalizeRegistration($registrationSeen) !== self::normalizeRegistration($active->registration_no)) {
                return ReceiptLineStatus::RegistrationMismatch;
            }
        }

        $limit = $product->min_shelf_life_months ?? $defaultMinMonths;
        if ($shelfLifeMonths !== null && $limit > 0 && BigDecimal::of($shelfLifeMonths)->isLessThan($limit)) {
            return ReceiptLineStatus::ShortShelfLife;
        }

        return ReceiptLineStatus::Ok;
    }

    /**
     * Biaya per satuan dasar dari baris PO (BR-159, SEC-104): harga × (1 − diskon%) / faktor satuan,
     * 4 desimal half-up.
     */
    public static function unitCostFromPo(string $unitPrice, string $discountPct, string $factor): string
    {
        return (string) BigDecimal::of($unitPrice)
            ->multipliedBy(BigDecimal::of('100')->minus($discountPct))
            ->dividedBy(BigDecimal::of('100')->multipliedBy($factor), 4, RoundingMode::HalfUp);
    }
}
