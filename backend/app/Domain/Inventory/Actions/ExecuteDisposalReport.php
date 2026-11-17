<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Accounting\Outbox\AccountingOutbox;
use App\Domain\Accounting\Outbox\EventCode;
use App\Domain\Accounting\Outbox\EventSource;
use App\Domain\Accounting\Period\PeriodGuard;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\DisposalMethod;
use App\Domain\Inventory\Enums\DisposalReportStatus;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\LedgerResult;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\DisposalReport;
use App\Domain\Inventory\Models\DisposalReportLine;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Purchasing\Actions\SavePurchaseReturn;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Time\DisplayTime;
use App\Support\Upload\SecureUpload;
use App\Support\Upload\UploadTarget;
use Illuminate\Http\UploadedFile;

/**
 * Laksanakan berita acara yang disetujui (BR-163, 15 §6.6). Bukti wajib untuk SEMUA metode: scan BA
 * bertanda tangan (PDF/JPG/PNG/WEBP menurut isi, SEC-109), ≥ 1 foto, ≥ 2 saksi `{name, role,
 * organization}` tanpa NIK (divalidasi ExecuteDisposalRequest), tempat pelaksanaan.
 *
 * - `destroy` / `handover_to_authority`: `PeriodGuard` tanggal eksekusi → mutasi `disposal` per baris
 *   (reservasi BA dikonsumsi; bila sudah kedaluwarsa, keluar langsung dari qty tersedia) → batch
 *   bersaldo 0 menjadi `disposed` → satu outbox `inventory.written_off` (`inventory_loss` ↔ `inventory`).
 * - `return_to_principal`: reservasi BA dilepas lalu dibuat retur ke prinsipal draf (T-081) berisi
 *   batch yang sama; tanpa mutasi `disposal` maupun `written_off` (stok keluar lewat alur retur,
 *   keputusan Mas PA-69).
 */
final class ExecuteDisposalReport
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StockLedger $ledger,
        private readonly SecureUpload $uploads,
        private readonly SavePurchaseReturn $returns,
    ) {}

    /**
     * @param  array{executed_location: string, witnesses: list<array{name: string, role: string, organization: string}>, supplier_id: int|null, return_reason_id: int|null}  $data
     * @param  list<UploadedFile>  $photos
     */
    public function handle(DisposalReport $report, array $data, UploadedFile $document, array $photos, User $actor): DisposalReport
    {
        return StockLedger::transaction(function () use ($report, $data, $document, $photos, $actor) {
            $locked = DisposalReport::query()->lockForUpdate()->findOrFail($report->id);
            if ($locked->status !== DisposalReportStatus::Approved) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Hanya berita acara yang sudah disetujui yang bisa dilaksanakan.');
            }
            $date = DisplayTime::toLocal(now())->toDateString();
            $lines = $locked->lines()->get();
            $extra = [];

            if ($locked->method === DisposalMethod::ReturnToPrincipal) {
                CancelDisposalReport::releaseReservations($this->ledger, $locked);
                $return = $this->returns->handle(null, [
                    'supplier_id' => $data['supplier_id'],
                    'stock_location_id' => $locked->stock_location_id,
                    'reason_id' => $data['return_reason_id'],
                    'goods_receipt_id' => null,
                    'note' => "Dari berita acara {$locked->number}",
                ], array_values($lines->map(fn (DisposalReportLine $l) => ['stock_batch_id' => $l->stock_batch_id, 'qty_base' => $l->qty_base])->all()), $actor);
                $extra = ['purchase_return_id' => $return->id, 'purchase_return_number' => $return->number];
            } else {
                PeriodGuard::assertOpen($date);
                $journal = [];
                foreach ($lines as $line) {
                    $result = $this->dispose($locked, $line, $date);
                    $value = (string) $result->value();
                    $dims = ['stock_location_id' => $locked->stock_location_id, 'product_id' => $line->product_id];
                    $journal[] = ['role' => 'inventory_loss', 'debit' => $value, ...$dims];
                    $journal[] = ['role' => 'inventory', 'credit' => $value, ...$dims];

                    $batch = StockBatch::query()->findOrFail($line->stock_batch_id);
                    if ($batch->onHand()->isZero()) {
                        $this->ledger->setStatus($batch, StockBatchStatus::Disposed, null, $actor, "BA {$locked->number}");
                    }
                }
                AccountingOutbox::record(EventCode::InventoryWrittenOff, new EventSource(DisposalReport::SOURCE_TYPE, $locked->id), $date, [
                    'lines' => $journal,
                    'memo' => "Berita acara {$locked->number} ({$locked->method->label()})",
                ]);
            }

            $documentPath = $this->uploads->store($document, UploadTarget::privateDocument("disposal/{$locked->id}", 'document'))->path;
            $photoPaths = array_map(fn (UploadedFile $f) => $this->uploads->store($f, UploadTarget::privatePhoto("disposal/{$locked->id}", 'photos'))->path, $photos);

            $before = $locked->audited();
            $locked->forceFill([
                'status' => DisposalReportStatus::Executed,
                'executed_at' => now(),
                'executed_location' => $data['executed_location'],
                'witnesses' => $data['witnesses'],
                'document_path' => $documentPath,
                'photo_paths' => $photoPaths,
                'updated_by' => $actor->id,
            ])->save();
            $this->audit->record('disposal_report.executed', $locked, $before, [...$locked->audited(), 'photos' => count($photoPaths), ...$extra]);

            return $locked;
        });
    }

    /** Konsumsi reservasi BA bila masih aktif; bila sudah dilepas job kedaluwarsa, keluarkan dari qty tersedia. */
    private function dispose(DisposalReport $report, DisposalReportLine $line, string $date): LedgerResult
    {
        $reservation = StockReservation::query()->where('source_type', DisposalReport::SOURCE_TYPE)->where('source_id', $report->id)
            ->where('source_line_id', $line->id)->where('status', ReservationStatus::Active->value)->first();
        $note = "BA {$report->number}";

        return $reservation !== null
            ? $this->ledger->consume($reservation, MovementType::Disposal, $date, $note)
            : $this->ledger->issue(StockBatch::query()->findOrFail($line->stock_batch_id), $line->qty(),
                new Source(DisposalReport::SOURCE_TYPE, $report->id, $line->id), MovementType::Disposal, $date, $note);
    }
}
