<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Catalog\Units\UnitConverter;
use App\Domain\Inventory\Enums\BinZone;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Models\SerialNumber;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Enums\ReceiptLineStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use App\Domain\Purchasing\Support\ReceiptChecks;
use App\Domain\Purchasing\Support\ReceiptLineRules;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tambah/ubah draf penerimaan (FR-A25, BR-158, SEC-104). Dengan PO: PO wajib `approved`/
 * `partially_received` (lainnya `PO_NOT_APPROVED`), supplier & gudang mengikuti PO, setiap baris
 * merujuk baris PO itu, produk & satuan mengikuti baris PO, dan biaya per satuan dasar SELALU dihitung
 * dari harga PO (tidak bisa diubah di penerimaan; pengecualian SEC-104 "dengan alasan" tidak dibuat,
 * PA-65). Tanpa PO hanya bila `purchasing.allow_receipt_without_po` dan pelaku `purchase_orders.create`;
 * biaya diketik.
 *
 * Mengubah penerimaan `checked` mengembalikannya ke `draft` (hasil periksa dibuang). Baris yang
 * dikirim dengan `id` dipertahankan agar nomor seri yang sudah diinput tidak hilang; baris lain
 * diganti.
 */
final class SaveGoodsReceipt
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NumberGenerator $numbers,
        private readonly UnitConverter $units,
    ) {}

    /**
     * @param  array<string, mixed>  $data  sudah divalidasi GoodsReceiptRequest
     * @param  list<array<string, mixed>>  $lines
     */
    public function handle(?GoodsReceipt $receipt, array $data, array $lines, User $actor): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $data, $lines, $actor) {
            $order = null;
            if (($data['purchase_order_id'] ?? null) !== null) {
                $order = PurchaseOrder::query()->lockForUpdate()->find((int) $data['purchase_order_id']);
                if ($order === null) {
                    throw ValidationException::withMessages(['purchase_order_id' => 'PO tidak ditemukan.']);
                }
                ReceiptChecks::assertPurchaseOrderOpen($order);
                $supplierId = $order->supplier_id;
                $locationId = $order->stock_location_id;
            } else {
                if (! (bool) setting('purchasing.allow_receipt_without_po') || ! $actor->can('purchase_orders.create')) {
                    throw ValidationException::withMessages(['purchase_order_id' => 'Penerimaan harus merujuk PO yang sudah disetujui.']);
                }
                $supplierId = (int) $data['supplier_id'];
                $locationId = (int) $data['stock_location_id'];
            }

            $location = StockLocation::query()->findOrFail($locationId);
            $receivedAt = CarbonImmutable::parse((string) $data['received_at'], DisplayTime::timezone())->utc();
            $receivedDate = DisplayTime::toLocal($receivedAt)->toDateString();
            $built = $this->buildLines($lines, $order, $location, $receivedDate);

            $values = [
                'purchase_order_id' => $order?->id,
                'supplier_id' => $supplierId,
                'stock_location_id' => $location->id,
                'received_at' => $receivedAt,
                'supplier_delivery_no' => trim((string) $data['supplier_delivery_no']),
                'vehicle_plate' => ($data['vehicle_plate'] ?? '') === '' ? null : mb_strtoupper(str_replace(' ', '', (string) $data['vehicle_plate'])),
                'note' => ($data['note'] ?? '') === '' ? null : $data['note'],
            ];

            if ($receipt === null) {
                $saved = new GoodsReceipt;
                $saved->forceFill([
                    ...$values,
                    'number' => $this->numbers->next('goods_receipt', isTaken: fn (string $n) => GoodsReceipt::query()->where('number', $n)->exists()),
                    'status' => GoodsReceiptStatus::Draft,
                    'created_by' => $actor->id, 'updated_by' => $actor->id,
                ])->save();
                $before = null;
                $existing = collect();
            } else {
                $saved = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
                if (! $saved->status->isEditable()) {
                    throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $saved->status->value],
                        'Penerimaan yang sudah diposting atau menunggu persetujuan tidak bisa diubah.');
                }
                $before = $saved->audited();
                // Hasil periksa dibuang: periksa ulang wajib setelah isi berubah.
                $saved->forceFill([...$values, 'status' => GoodsReceiptStatus::Draft, 'checked_by' => null, 'checked_at' => null, 'updated_by' => $actor->id])->save();
                $existing = GoodsReceiptLine::query()->where('goods_receipt_id', $saved->id)->get()->keyBy('id');
            }

            $kept = [];
            foreach ($built as $line) {
                $id = $line['id'];
                $current = $id === null ? null : $existing->get($id);
                if ($current !== null) {
                    // Produk berubah → nomor seri lama tidak lagi berlaku untuk baris ini.
                    if ($current->product_id !== $line['row']['product_id']) {
                        SerialNumber::query()->where('goods_receipt_line_id', $current->id)->delete();
                    }
                    $current->forceFill($line['row'])->save();
                    $kept[] = $current->id;
                } else {
                    $new = (new GoodsReceiptLine)->forceFill([...$line['row'], 'goods_receipt_id' => $saved->id]);
                    $new->save();
                    $kept[] = $new->id;
                }
            }
            $removed = $existing->keys()->diff($kept)->all();
            if ($removed !== []) {
                SerialNumber::query()->whereIn('goods_receipt_line_id', $removed)->delete();
                GoodsReceiptLine::query()->whereIn('id', $removed)->delete();
            }

            $this->audit->record($before === null ? 'goods_receipt.created' : 'goods_receipt.updated', $saved, $before, [
                ...$saved->audited(), 'lines' => count($built),
            ]);

            return $saved;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{id: int|null, row: array<string, mixed>}>
     */
    private function buildLines(array $lines, ?PurchaseOrder $order, StockLocation $location, string $receivedDate): array
    {
        $poLines = $order === null ? collect() : PurchaseOrderLine::query()->with('product', 'productUnit.unit')
            ->where('purchase_order_id', $order->id)->get()->keyBy('id');
        $units = $order !== null ? collect() : ProductUnit::query()->with('product', 'unit')
            ->whereIn('id', array_filter(array_column($lines, 'product_unit_id')))->get()->keyBy('id');
        $bins = StockBin::query()->where('stock_location_id', $location->id)->where('is_active', true)->get()->keyBy('id');
        $reasons = ReferenceItem::query()->forGroup(ReferenceGroup::ReceiptRejectReason)->pluck('id')->flip();

        $built = [];
        foreach ($lines as $i => $line) {
            $field = fn (string $f) => "lines.{$i}.{$f}";

            if ($order !== null) {
                $poLine = $poLines->get((int) ($line['purchase_order_line_id'] ?? 0));
                if ($poLine === null) {
                    throw ValidationException::withMessages([$field('purchase_order_line_id') => 'Baris PO tidak ditemukan pada PO ini.']);
                }
                $unit = $poLine->productUnit;
                $product = $poLine->product;
                $unitCost = ReceiptLineRules::unitCostFromPo($poLine->unit_price, $poLine->discount_pct, $unit->conversionFactor());
            } else {
                $poLine = null;
                $unit = $units->get((int) ($line['product_unit_id'] ?? 0));
                if ($unit === null || ! $unit->is_active) {
                    throw ValidationException::withMessages([$field('product_unit_id') => 'Satuan tidak ditemukan atau nonaktif.']);
                }
                $product = $unit->product;
                if (($line['unit_cost'] ?? '') === '') {
                    throw ValidationException::withMessages([$field('unit_cost') => 'Biaya per satuan dasar wajib diisi untuk penerimaan tanpa PO.']);
                }
                $unitCost = (string) $line['unit_cost'];
            }
            if (! $product->is_purchasable || $product->trashed()) {
                throw ValidationException::withMessages([$field('purchase_order_line_id') => "Produk {$product->sku} tidak bisa dibeli."]);
            }

            $qty = Qty::of((string) $line['qty_received']);
            $rejected = Qty::of(($line['qty_rejected'] ?? '') === '' ? '0' : (string) $line['qty_rejected']);
            try {
                $this->units->assertAllowed($qty, $unit);
                $this->units->assertAllowed($rejected, $unit);
            } catch (ApiError) {
                throw ValidationException::withMessages([$field('qty_received') => 'Jumlah untuk satuan ini harus bilangan bulat.']);
            }
            if (! $qty->isPositive() && ! $rejected->isPositive()) {
                throw ValidationException::withMessages([$field('qty_received') => 'Isi jumlah diterima atau jumlah ditolak.']);
            }
            $reasonId = ($line['reject_reason_id'] ?? null) === null ? null : (int) $line['reject_reason_id'];
            if ($rejected->isPositive() && ($reasonId === null || ! $reasons->has($reasonId))) {
                throw ValidationException::withMessages([$field('reject_reason_id') => 'Pilih alasan barang ditolak.']);
            }

            $batchNo = trim((string) ($line['batch_no'] ?? ''));
            if ($product->tracks_batch && $batchNo === '') {
                throw ValidationException::withMessages([$field('batch_no') => 'Nomor batch wajib untuk produk ini.']);
            }
            $expiry = ($line['expiry_date'] ?? '') === '' ? null : (string) $line['expiry_date'];
            if ($product->tracks_expiry && $expiry === null) {
                throw ValidationException::withMessages([$field('expiry_date') => 'Tanggal kedaluwarsa wajib untuk produk ini.']);
            }
            // BR-103: batch kedaluwarsa tidak boleh diterima; ditolak sejak draf agar tidak sampai diperiksa.
            try {
                ReceiptLineRules::assertNotExpired($receivedDate, $expiry);
            } catch (ApiError $e) {
                throw new ApiError($e->errorCode, [$field('expiry_date') => $e->publicMessage()]);
            }
            $seen = trim((string) ($line['registration_no_seen'] ?? ''));
            if ($product->requires_registration && $seen === '') {
                throw ValidationException::withMessages([$field('registration_no_seen') => 'Nomor izin edar di kemasan wajib dicatat untuk produk ini.']);
            }

            $binId = ($line['stock_bin_id'] ?? null) === null ? null : (int) $line['stock_bin_id'];
            if ($binId !== null) {
                $bin = $bins->get($binId);
                if ($bin === null) {
                    throw ValidationException::withMessages([$field('stock_bin_id') => 'Rak tidak ditemukan di lokasi ini.']);
                }
                if ($bin->zone === BinZone::Quarantine) {
                    throw ValidationException::withMessages([$field('stock_bin_id') => 'Rak karantina hanya untuk baris yang izin edarnya tidak cocok (otomatis).']);
                }
            }

            $built[] = [
                'id' => ($line['id'] ?? null) === null ? null : (int) $line['id'],
                'row' => [
                    'purchase_order_line_id' => $poLine?->id,
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'qty_received' => (string) $qty,
                    'qty_received_base' => (string) $this->units->toBase($qty, $unit),
                    'qty_rejected_base' => (string) $this->units->toBase($rejected, $unit),
                    'reject_reason_id' => $rejected->isPositive() ? $reasonId : null,
                    'batch_no' => $batchNo === '' ? BatchKey::NO_BATCH : $batchNo,
                    'expiry_date' => $expiry,
                    'registration_no_seen' => $seen === '' ? null : $seen,
                    'stock_bin_id' => $binId,
                    'unit_cost' => $unitCost,
                    'shelf_life_months' => null,
                    'line_status' => ReceiptLineStatus::Ok,
                    'stock_batch_id' => null,
                ],
            ];
        }

        return $built;
    }
}
