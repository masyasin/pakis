<?php

namespace App\Domain\Inventory\Policies;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Enums\DisposalReportStatus;
use App\Domain\Inventory\Models\DisposalReport;
use App\Models\User;

/**
 * Berita acara pemusnahan (15 §5/§6.6, BR-163): semua aksi `stock_disposals.manage` + lokasi dalam
 * scope Stok (di luar → 404 di controller, IDOR 06 §5). Penyetuju memutuskan lewat kotak persetujuan.
 */
class DisposalReportPolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function view(User $user, DisposalReport $report): bool
    {
        return $user->can('stock_disposals.manage') && $this->inScope($user, $report);
    }

    public function submit(User $user, DisposalReport $report): bool
    {
        return $this->view($user, $report) && $report->status === DisposalReportStatus::Draft;
    }

    public function execute(User $user, DisposalReport $report): bool
    {
        return $this->view($user, $report) && $report->status === DisposalReportStatus::Approved;
    }

    public function cancel(User $user, DisposalReport $report): bool
    {
        return $this->view($user, $report)
            && in_array($report->status, [DisposalReportStatus::Draft, DisposalReportStatus::Approved, DisposalReportStatus::Rejected], true);
    }

    public function inScope(User $user, DisposalReport $report): bool
    {
        return $this->scope->forStock(DisposalReport::query()->whereKey($report->id), $user, 'disposal_reports.stock_location_id')->exists();
    }
}
