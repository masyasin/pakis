<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Catalog\Units\UnitConverter;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Support\PurchaseOrderTotals;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Money;
use App\Support\Money\Qty;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tambah/ubah PO (FR-A24, BR-160). Hanya draf yang bisa diubah (PO disetujui → tutup lalu PO baru).
 * Supplier wajib aktif; produk `is_purchasable`, satuan milik produk & aktif, qty > 0 (bulat untuk
 * satuan `integer_only`). Baris diganti utuh; total dihitung server — total kiriman klien diabaikan.
 * Nomor dari `NumberGenerator('purchase_order')` saat dibuat.
 */
final class SavePurchaseOrder
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NumberGenerator $numbers,
        private readonly UnitConverter $units,
    ) {}

    /**
     * @param  array<string, mixed>  $data  sudah divalidasi PurchaseOrderRequest
     * @param  list<array{product_unit_id: int, qty: string, unit_price: string, discount_pct: string, note: string|null}>  $lines
     */
    public function handle(?PurchaseOrder $order, array $data, array $lines, int $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data, $lines, $userId) {
            $supplier = Supplier::query()->lockForUpdate()->find((int) $data['supplier_id']);
            if ($supplier === null || ! $supplier->is_active) {
                throw ValidationException::withMessages(['supplier_id' => 'Supplier tidak aktif. PO hanya bisa dibuat ke supplier aktif.']);
            }

            $built = $this->buildLines($lines);
            $totals = PurchaseOrderTotals::document(array_column($built, 'amounts'));
            $values = [
                'supplier_id' => $supplier->id,
                'stock_location_id' => (int) $data['stock_location_id'],
                'order_date' => (string) $data['order_date'],
                'expected_date' => ($data['expected_date'] ?? '') === '' ? null : $data['expected_date'],
                'payment_term_days' => (int) ($data['payment_term_days'] ?? $supplier->payment_term_days),
                'note' => $data['note'] ?? null,
                ...$totals,
            ];

            if ($order === null) {
                $saved = new PurchaseOrder;
                $saved->forceFill([
                    ...$values,
                    'number' => $this->numbers->next('purchase_order', isTaken: fn (string $n) => PurchaseOrder::query()->where('number', $n)->exists()),
                    'status' => PurchaseOrderStatus::Draft,
                    'created_by' => $userId, 'updated_by' => $userId,
                ])->save();
                $before = null;
            } else {
                $saved = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
                if ($saved->status !== PurchaseOrderStatus::Draft) {
                    throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $saved->status->value],
                        'PO yang sudah diajukan atau disetujui tidak bisa diubah. Tutup PO lalu buat PO baru.');
                }
                $before = $saved->audited();
                $saved->forceFill([...$values, 'updated_by' => $userId])->save();
                PurchaseOrderLine::query()->where('purchase_order_id', $saved->id)->delete();
            }

            foreach ($built as $no => $line) {
                (new PurchaseOrderLine)->forceFill([...$line['row'], 'purchase_order_id' => $saved->id, 'line_no' => $no + 1])->save();
            }

            $this->audit->record($before === null ? 'purchase_order.created' : 'purchase_order.updated', $saved, $before, [
                ...$saved->audited(), 'lines' => count($built),
            ]);

            return $saved;
        });
    }

    /**
     * @param  list<array{product_unit_id: int, qty: string, unit_price: string, discount_pct: string, note: string|null}>  $lines
     * @return list<array{row: array<string, mixed>, amounts: array{gross: Money, total: Money}}>
     */
    private function buildLines(array $lines): array
    {
        $units = ProductUnit::query()->with('product', 'unit')->whereIn('id', array_column($lines, 'product_unit_id'))->get()->keyBy('id');
        $built = [];
        foreach ($lines as $i => $line) {
            $unit = $units->get($line['product_unit_id']);
            if ($unit === null || ! $unit->is_active) {
                throw ValidationException::withMessages(["lines.{$i}.product_unit_id" => 'Satuan tidak ditemukan atau nonaktif.']);
            }
            $product = $unit->product;
            if (! $product->is_purchasable || $product->trashed()) {
                throw ValidationException::withMessages(["lines.{$i}.product_unit_id" => "Produk {$product->sku} tidak bisa dibeli."]);
            }

            $qty = Qty::of($line['qty']);
            try {
                $this->units->assertAllowed($qty, $unit);
            } catch (ApiError) {
                throw ValidationException::withMessages(["lines.{$i}.qty" => 'Jumlah untuk satuan ini harus bilangan bulat.']);
            }
            $amounts = PurchaseOrderTotals::line((string) $qty, $line['unit_price'], $line['discount_pct']);

            $built[] = [
                'row' => [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'qty' => (string) $qty,
                    'qty_base' => (string) $this->units->toBase($qty, $unit),
                    'unit_price' => $line['unit_price'],
                    'discount_pct' => $line['discount_pct'],
                    'line_total' => (string) $amounts['total'],
                    'note' => $line['note'],
                ],
                'amounts' => $amounts,
            ];
        }

        return $built;
    }
}
