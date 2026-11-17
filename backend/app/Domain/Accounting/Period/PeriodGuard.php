<?php

namespace App\Domain\Accounting\Period;

use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Tanggal kunci akuntansi (ADR-115, PF-3, 15 §11.4).
 *
 * Setiap Action yang memanggil `AccountingOutbox::record()` memanggil `assertOpen()` dengan
 * tanggal bisnis dokumen SEBELUM menulis mutasi, di transaksi yang sama — dijaga
 * tests/Arch/PeriodGuardArchTest. Tanggal ≤ `accounting.lock_date` ditolak `422 PERIOD_LOCKED`
 * sehingga transaksi batal utuh; kosong = tidak ada periode terkunci. Perbandingan string `Y-m-d`
 * setara dengan perbandingan tanggal dan tidak terpengaruh zona waktu.
 */
final class PeriodGuard
{
    /**
     * @param  string|null  $field  nama field tanggal di form; bila diisi, pesan tampil di field itu (galat web, PA-16)
     *
     * @throws ApiError PERIOD_LOCKED
     */
    public static function assertOpen(DateTimeInterface|string $businessDate, ?string $field = null): void
    {
        $date = BusinessDate::normalize($businessDate);
        $lock = self::lockDate();
        if ($lock === null || $date > $lock) {
            return;
        }

        $message = sprintf(
            'Tanggal %s masuk periode akuntansi yang sudah dikunci (sampai %s). Pakai tanggal sesudah tanggal kunci.',
            self::display($date),
            self::display($lock),
        );

        throw new ApiError(ErrorCode::PERIOD_LOCKED, details: $field === null ? [] : [$field => $message], publicMessage: $message);
    }

    public static function isOpen(DateTimeInterface|string $businessDate): bool
    {
        $lock = self::lockDate();

        return $lock === null || BusinessDate::normalize($businessDate) > $lock;
    }

    /** Tanggal kunci `Y-m-d`, atau null bila belum ada periode terkunci. */
    public static function lockDate(): ?string
    {
        $value = setting('accounting.lock_date');

        return is_string($value) && $value !== '' ? BusinessDate::normalize($value) : null;
    }

    private static function display(string $date): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date)?->translatedFormat('j M Y') ?? $date;
    }
}
