<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\DisposalReportStatus;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\DisposalReport;
use App\Domain\Inventory\Models\StockReservation;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;

/**
 * Batalkan berita acara (draf, disetujui, atau ditolak; PA-69). Yang menunggu persetujuan ditarik
 * lewat persetujuannya; yang sudah dilaksanakan final. Reservasi batch BA yang disetujui dilepas.
 */
final class CancelDisposalReport
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StockLedger $ledger,
    ) {}

    public function handle(DisposalReport $report, int $userId): DisposalReport
    {
        return StockLedger::transaction(function () use ($report, $userId) {
            $locked = DisposalReport::query()->lockForUpdate()->findOrFail($report->id);
            if (! in_array($locked->status, [DisposalReportStatus::Draft, DisposalReportStatus::Approved, DisposalReportStatus::Rejected], true)) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Berita acara ini tidak bisa dibatalkan lagi.');
            }
            self::releaseReservations($this->ledger, $locked);
            $before = $locked->audited();
            $locked->forceFill(['status' => DisposalReportStatus::Cancelled, 'updated_by' => $userId])->save();
            $this->audit->record('disposal_report.cancelled', $locked, $before, $locked->audited());

            return $locked;
        });
    }

    public static function releaseReservations(StockLedger $ledger, DisposalReport $report): void
    {
        StockReservation::query()->where('source_type', DisposalReport::SOURCE_TYPE)->where('source_id', $report->id)
            ->where('status', ReservationStatus::Active->value)->orderBy('id')->get()
            ->each(fn (StockReservation $r) => $ledger->release($r));
    }
}
