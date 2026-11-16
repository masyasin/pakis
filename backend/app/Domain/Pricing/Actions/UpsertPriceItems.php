<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Pricing\Models\PriceListItem;
use App\Domain\Pricing\Models\PriceListVersion;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Money;
use App\Support\Money\Qty;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Isi/ubah item versi daftar harga (FR-A19). Hanya versi `draft` (SEC-106): versi lain →
 * `PRICE_VERSION_LOCKED` 409 (trigger DB menolak juga bila jalur ini terlewati). Baris dikenali dari
 * (satuan, min_qty): sama = ubah harga, baru = tambah. `remove` = id baris yang dihapus.
 * `product_id` selalu diambil dari satuannya (tidak dipercaya dari klien).
 */
final class UpsertPriceItems
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<array{product_unit_id: int, min_qty: string, price: string}>  $rows
     * @param  list<int>  $remove
     * @return array{saved: int, removed: int}
     */
    public function handle(PriceListVersion $version, array $rows, array $remove, ?int $userId): array
    {
        return DB::transaction(function () use ($version, $rows, $remove, $userId) {
            $locked = PriceListVersion::query()->lockForUpdate()->findOrFail($version->id);
            if (! $locked->isDraft()) {
                throw new ApiError(ErrorCode::PRICE_VERSION_LOCKED, ['status' => $locked->status->value]);
            }

            $units = ProductUnit::query()->whereIn('id', array_column($rows, 'product_unit_id'))->get()->keyBy('id');
            $saved = 0;
            foreach ($rows as $index => $row) {
                $unit = $units->get($row['product_unit_id']);
                if ($unit === null || ! $unit->is_active) {
                    throw ValidationException::withMessages(["items.{$index}.product_unit_id" => 'Satuan tidak ditemukan atau sudah nonaktif.']);
                }
                $minQty = (string) Qty::of($row['min_qty']);
                $item = PriceListItem::query()->where('price_list_version_id', $locked->id)
                    ->where('product_unit_id', $unit->id)->where('min_qty', $minQty)->first() ?? new PriceListItem;
                $item->forceFill([
                    'price_list_version_id' => $locked->id,
                    'product_id' => $unit->product_id,
                    'product_unit_id' => $unit->id,
                    'min_qty' => $minQty,
                    'price' => (string) Money::of($row['price']),
                ]);
                if ($item->isDirty()) {
                    $item->save();
                    $saved++;
                }
            }

            $removed = $remove === [] ? 0 : PriceListItem::query()->where('price_list_version_id', $locked->id)->whereIn('id', $remove)->delete();

            if ($saved > 0 || $removed > 0) {
                $locked->forceFill(['updated_by' => $userId])->touch();
                // Ringkasan saja: daftar harga bukan data pribadi, tetapi audit per baris akan membanjiri log.
                $this->audit->record('price_items.updated', $locked, null, ['saved' => $saved, 'removed' => $removed]);
            }

            return ['saved' => $saved, 'removed' => $removed];
        });
    }
}
