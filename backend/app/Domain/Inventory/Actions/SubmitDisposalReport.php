<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Approvables\DisposalApproval;
use App\Domain\Inventory\Enums\DisposalReportStatus;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\DisposalReport;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;

/**
 * Ajukan berita acara (draf → `pending_approval`, 15 §6.6): approval `stock_disposal` dengan nominal
 * `total_cost` (HPP), satu langkah `stock_disposals.approve`. Disetujui → batch direservasi (PA-69).
 */
final class SubmitDisposalReport
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ApprovalEngine $engine,
    ) {}

    public function handle(DisposalReport $report, User $actor): DisposalReport
    {
        return StockLedger::transaction(function () use ($report, $actor) {
            $locked = DisposalApproval::query()->lockForUpdate()->findOrFail($report->id);
            if ($locked->status !== DisposalReportStatus::Draft) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Hanya berita acara draf yang bisa diajukan.');
            }
            $before = $locked->audited();
            $locked->forceFill(['status' => DisposalReportStatus::PendingApproval, 'updated_by' => $actor->id])->save();
            $this->audit->record('disposal_report.submitted', $locked, $before, $locked->audited());

            $request = $this->engine->request($locked, $actor);
            if ($request !== null) {
                $locked->forceFill(['approval_request_id' => $request->id])->save();
            }

            return $locked->refresh();
        });
    }
}
