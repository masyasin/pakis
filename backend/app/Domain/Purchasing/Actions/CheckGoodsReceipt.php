<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Queries\ActiveRegistration;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Enums\ReceiptLineStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Support\ReceiptChecks;
use App\Domain\Purchasing\Support\ReceiptLineRules;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Periksa penerimaan (`draft → checked`, 15 §6.6, BR-103, BR-158): hitung sisa umur & status tiap
 * baris (ED lewat → `BATCH_EXPIRED_ON_RECEIPT`), cek over-receipt kumulatif per baris PO, dan jumlah
 * nomor seri produk alat. Pemeriksa dicatat di `checked_by` untuk aturan dua orang saat posting
 * (SEC-104).
 */
final class CheckGoodsReceipt
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(GoodsReceipt $receipt, User $actor): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $actor) {
            $locked = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            if ($locked->status !== GoodsReceiptStatus::Draft) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Hanya penerimaan draf yang bisa diperiksa.');
            }
            $order = $locked->purchase_order_id === null ? null : PurchaseOrder::query()->lockForUpdate()->findOrFail($locked->purchase_order_id);
            ReceiptChecks::assertPurchaseOrderOpen($order);

            $lines = GoodsReceiptLine::query()->with('product')->where('goods_receipt_id', $locked->id)->orderBy('id')->get();
            if ($lines->isEmpty()) {
                throw new ApiError(ErrorCode::VALIDATION_FAILED, ['lines' => 'Isi minimal satu baris sebelum diperiksa.']);
            }

            $receivedDate = $locked->businessDate();
            $defaultMin = (int) setting('inventory.min_shelf_life_months');
            foreach ($lines->values() as $i => $line) {
                $expiry = $line->expiry_date?->toDateString();
                try {
                    ReceiptLineRules::assertNotExpired($receivedDate, $expiry);
                } catch (ApiError $e) {
                    throw new ApiError($e->errorCode, ['lines' => [$i => ['expiry_date' => $e->publicMessage()]], "lines.{$i}.expiry_date" => $e->publicMessage()]);
                }
                $shelfLife = ReceiptLineRules::shelfLifeMonths($receivedDate, $expiry);
                $active = $line->product->requires_registration ? ActiveRegistration::for($line->product, $locked->received_at) : null;
                $line->forceFill([
                    'shelf_life_months' => $shelfLife,
                    'line_status' => ReceiptLineRules::status($line->product, $shelfLife, $line->registration_no_seen, $active, $defaultMin),
                ])->save();
            }

            ReceiptChecks::assertWithinTolerance($locked, $lines);
            ReceiptChecks::assertSerialCounts($lines);

            $before = $locked->audited();
            $locked->forceFill([
                'status' => GoodsReceiptStatus::Checked,
                'checked_by' => $actor->id,
                'checked_at' => now(),
                'updated_by' => $actor->id,
            ])->save();
            $this->audit->record('goods_receipt.checked', $locked, $before, [
                ...$locked->audited(),
                'short_shelf_life' => $lines->filter(fn (GoodsReceiptLine $l) => $l->line_status === ReceiptLineStatus::ShortShelfLife)->count(),
                'registration_mismatch' => $lines->filter(fn (GoodsReceiptLine $l) => $l->line_status === ReceiptLineStatus::RegistrationMismatch)->count(),
            ]);

            return $locked->refresh();
        });
    }
}
