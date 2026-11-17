<?php

namespace App\Domain\Inventory\Allocation;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Queries\ActiveRegistration;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\LedgerDate;
use App\Domain\Inventory\Ledger\StockLine;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockLocation;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Alokasi FEFO (BR-102, FR-A22): batch mana yang keluar lebih dulu untuk qty satuan dasar tertentu.
 *
 * Read-only dan TANPA kunci, sengaja: alokasi dipakai juga untuk pratinjau (form mutasi, saran
 * BatchPicker) yang tidak boleh menahan baris. Pemanggil yang mengeksekusi meneruskan hasilnya ke
 * `StockLedger` di dalam `StockLedger::transaction()`; ledger mengunci baris urut id dan memeriksa
 * ulang status & qty tersedia, jadi alokasi yang basi gagal dengan aman (`INSUFFICIENT_STOCK`).
 *
 * Batch layak: `status = available`, qty tersedia (`on_hand − reserved`) > 0, dan `expiry_date`
 * kosong atau > asOf + `inventory.min_sell_days_before_expiry`. Urutan: ED terdekat, ED kosong
 * terakhir, lalu `received_date`, lalu `id` (index `sbatch_fefo_idx`).
 */
final class FefoAllocator
{
    /**
     * @param  DateTimeInterface|string|null  $asOf  tanggal kalender LOKAL (`Y-m-d`) atau objek yang
     *                                               bagian tanggalnya sudah lokal; null = hari ini lokal.
     *
     * @throws ApiError PRODUCT_REGISTRATION_INACTIVE bila produk wajib izin tanpa izin berlaku (BR-152);
     *                  INSUFFICIENT_STOCK (`details.available_base`) bila total tersedia kurang.
     */
    public function allocate(Product $product, StockLocation $location, Qty $qtyBase, DateTimeInterface|string|null $asOf = null): Allocation
    {
        if (! $qtyBase->isPositive()) {
            throw new InvalidArgumentException('Qty alokasi harus lebih dari 0.');
        }
        $date = self::date($asOf);
        self::assertRegistered($product, $date);

        $lines = [];
        $remaining = $qtyBase;
        $available = Qty::zero();
        foreach (self::candidates($product->id, $location->id, $date)->get() as $batch) {
            $free = $batch->available();
            $available = $available->plus($free);
            if ($remaining->isPositive()) {
                $take = $free->compareTo($remaining) < 0 ? $free : $remaining;
                $lines[] = new StockLine($batch, $take);
                $remaining = $remaining->minus($take);
            }
        }

        if ($remaining->isPositive()) {
            // Qty sebagai objek (bukan string): renderer galat Inertia membaca `details` string sebagai
            // pesan field (pola InsufficientStock T-077).
            throw new ApiError(ErrorCode::INSUFFICIENT_STOCK, [
                'available_base' => $available,
                'product_id' => $product->id,
                'stock_location_id' => $location->id,
            ]);
        }

        return new Allocation($lines, $qtyBase);
    }

    /**
     * Query batch layak FEFO, sudah terurut. Dipakai bersama lookup `available_batches` agar pilihan
     * di layar = yang akan dialokasikan.
     *
     * @return Builder<StockBatch>
     */
    public static function candidates(int $productId, int $stockLocationId, DateTimeInterface|string|null $asOf = null): Builder
    {
        $cutoff = self::cutoff(self::date($asOf));

        return StockBatch::query()
            ->where('stock_batches.product_id', $productId)
            ->where('stock_batches.stock_location_id', $stockLocationId)
            ->where('stock_batches.status', StockBatchStatus::Available->value)
            ->where(fn (Builder $q) => $q->whereNull('stock_batches.expiry_date')->orWhere('stock_batches.expiry_date', '>', $cutoff))
            ->whereColumn('stock_batches.qty_on_hand', '>', 'stock_batches.qty_reserved')
            // SQL tetap (bukan input): ED kosong diurutkan terakhir.
            ->orderByRaw('stock_batches.expiry_date IS NULL')
            ->orderBy('stock_batches.expiry_date')
            ->orderBy('stock_batches.received_date')
            ->orderBy('stock_batches.id');
    }

    /** Batas ED: batch ber-ED ≤ tanggal ini tidak dialokasikan (asOf + `min_sell_days_before_expiry`). */
    public static function cutoff(string $date): string
    {
        return CarbonImmutable::parse($date)->addDays((int) setting('inventory.min_sell_days_before_expiry'))->toDateString();
    }

    /** Tanggal lokal `Y-m-d` dari argumen asOf (null = hari ini menurut `general.timezone`). */
    public static function date(DateTimeInterface|string|null $asOf): string
    {
        return $asOf === null ? DisplayTime::toLocal(now())->toDateString() : LedgerDate::normalize($asOf);
    }

    private static function assertRegistered(Product $product, string $date): void
    {
        if (! $product->requires_registration) {
            return;
        }
        // Tengah hari lokal: ActiveRegistration mengubah momen ke tanggal lokal, jadi tanggal tetap sama.
        $moment = CarbonImmutable::parse("{$date} 12:00:00", DisplayTime::timezone());
        if (ActiveRegistration::for($product, $moment) === null) {
            throw new ApiError(ErrorCode::PRODUCT_REGISTRATION_INACTIVE, ['product_id' => $product->id]);
        }
    }
}
