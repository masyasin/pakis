<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Accounting\Outbox\AccountingOutbox;
use App\Domain\Accounting\Outbox\EventCode;
use App\Domain\Accounting\Outbox\EventSource;
use App\Domain\Accounting\Period\PeriodGuard;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Enums\SerialStatus;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\SerialNumber;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Purchasing\Enums\PurchaseReturnStatus;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Purchasing\Models\PurchaseReturnLine;
use App\Domain\Purchasing\Support\ReturnJournal;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use App\Support\Time\DisplayTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Kirim retur yang disetujui (`approved → shipped`, FR-A26, BR-155, BR-159, 15 §11.4) dalam satu
 * `StockLedger::transaction` (PA-62): kunci dokumen (klik ganda → satu kiriman + `409 STATE_CONFLICT`),
 * `PeriodGuard` dengan tanggal kirim lokal, per baris `consume` reservasi aktif atau — bila reservasi
 * sudah dilepas job TTL — `issue` langsung dari batch (`INSUFFICIENT_STOCK` bila tidak cukup, PA-68),
 * tipe mutasi `purchase_return` (batch karantina/ED boleh, PA-63). Produk bernomor seri wajib memilih
 * nomor `in_stock` dari batch baris itu, sejumlah qty; statusnya menjadi `returned_to_principal`.
 * Satu event `inventory.purchase_returned` (ReturnJournal), tanpa `inventory.adjusted` terpisah.
 */
final class ShipPurchaseReturn
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array<int, list<string>>  $serials  id baris => nomor seri dikirim */
    public function handle(PurchaseReturn $return, array $serials, User $actor): PurchaseReturn
    {
        return StockLedger::transaction(function () use ($return, $serials, $actor) {
            $locked = PurchaseReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($locked->status !== PurchaseReturnStatus::Approved) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Hanya retur yang sudah disetujui dan belum dikirim yang bisa dikirim.');
            }
            $date = DisplayTime::toLocal(now())->toDateString();
            // ADR-115: tanggal bisnis kirim = tanggal mutasi & event di bawah.
            PeriodGuard::assertOpen($date, 'shipped_at');

            $lines = PurchaseReturnLine::query()->with('product:id,sku,tracks_serial')->where('purchase_return_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $picked = $this->assertSerials($lines, $serials);

            $journal = [];
            foreach ($lines as $line) {
                $reservation = $line->reservation_id === null ? null : StockReservation::query()->find($line->reservation_id);
                $note = "Retur {$locked->number}";
                $result = $reservation !== null && $reservation->status === ReservationStatus::Active
                    ? $this->ledger->consume($reservation, MovementType::PurchaseReturn, $date, $note)
                    : $this->ledger->issue(StockBatch::query()->findOrFail($line->stock_batch_id), Qty::of($line->qty_base),
                        new Source('purchase_return', $locked->id, $line->id), MovementType::PurchaseReturn, $date, $note);

                array_push($journal, ...ReturnJournal::lines($line->qty_base, $line->unit_cost, (string) $result->value(), [
                    'stock_location_id' => $locked->stock_location_id, 'product_id' => $line->product_id,
                ]));
            }

            if ($picked !== []) {
                SerialNumber::query()->whereIn('id', array_merge(...array_values($picked)))->update(['status' => SerialStatus::ReturnedToPrincipal->value, 'updated_at' => now()]);
            }
            if ($journal !== []) {
                AccountingOutbox::record(EventCode::InventoryPurchaseReturned, new EventSource('purchase_return', $locked->id), $date, [
                    'lines' => $journal,
                    'refs' => ['supplier_id' => $locked->supplier_id],
                ]);
            }

            $before = $locked->audited();
            $locked->forceFill(['status' => PurchaseReturnStatus::Shipped, 'shipped_at' => now(), 'updated_by' => $actor->id])->save();
            $this->audit->record('purchase_return.shipped', $locked, $before, [
                ...$locked->audited(),
                'serials' => array_map(fn (array $ids) => SerialNumber::query()->whereIn('id', $ids)->orderBy('serial_no')->pluck('serial_no')->all(), $picked),
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Produk bernomor seri (PA-68): nomor seri `in_stock` produk itu di batch baris, sejumlah qty.
     *
     * @param  Collection<int, PurchaseReturnLine>  $lines
     * @param  array<int, list<string>>  $serials
     * @return array<int, list<int>> id baris => id nomor seri
     */
    private function assertSerials($lines, array $serials): array
    {
        $picked = [];
        foreach ($lines->values() as $i => $line) {
            if (! $line->product->tracks_serial) {
                continue;
            }
            $wanted = array_values(array_unique(array_map(fn (string $s) => SerialNumber::normalize($s), $serials[$line->id] ?? [])));
            $expected = Qty::of($line->qty_base)->toBigDecimal()->toInt();
            $ids = SerialNumber::query()->where('product_id', $line->product_id)->where('stock_batch_id', $line->stock_batch_id)
                ->where('status', SerialStatus::InStock->value)->whereIn('serial_no', $wanted)->lockForUpdate()->pluck('id')->all();
            if (count($wanted) !== $expected || count($ids) !== $expected) {
                throw ValidationException::withMessages(["serials.{$line->id}" => "Pilih {$expected} nomor seri {$line->product->sku} yang masih di stok batch ini (baris ".($i + 1).').']);
            }
            $picked[$line->id] = array_values(array_map('intval', $ids));
        }

        return $picked;
    }
}
