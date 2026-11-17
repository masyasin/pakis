<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Accounting\Outbox\AccountingOutbox;
use App\Domain\Accounting\Outbox\EventCode;
use App\Domain\Accounting\Outbox\EventSource;
use App\Domain\Accounting\Period\PeriodGuard;
use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Support\OpnameVariance;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Money;
use App\Support\Money\Qty;
use Brick\Math\BigDecimal;

/**
 * Posting opname (BR-162(6)(7), 15 §11.4). Dipanggil SubmitStockOpname (tanpa selisih, tanggal
 * ajukan) atau OpnameVarianceApproval::onApproved (tanggal keputusan, keputusan Mas PA-67), selalu
 * di dalam transaksi pemanggil. `PeriodGuard` dulu; selisih dihitung ULANG saat posting (mutasi bisa
 * terus terjadi selama tinjau/approval, BR-162(2)); `adjust()` per baris selisih dengan alasan di
 * baris; batch temuan dibuat lewat BatchKey (`adjustment_in`, biaya `avg_cost`). Satu outbox
 * `inventory.adjusted` seimbang (`inventory` ↔ `inventory_variance`) bila ada mutasi.
 */
final class PostStockOpname
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StockLedger $ledger,
    ) {}

    /**
     * @param  string|null  $approvedAmount  nominal yang disetujui (posting dari approval); selisih yang
     *                                       membesar sejak diajukan (mutasi selama approval) tidak boleh
     *                                       ikut terposting dengan persetujuan tingkat lebih rendah
     */
    public function handle(StockOpname $opname, string $businessDate, ?int $postedBy, ?string $approvedAmount = null): StockOpname
    {
        PeriodGuard::assertOpen($businessDate);

        $location = StockLocation::query()->withTrashed()->findOrFail($opname->stock_location_id);
        $opname->setRelation('lines', $opname->lines()->get());
        $variances = OpnameVariance::for($opname);
        if ($approvedAmount !== null && OpnameVariance::totalValue($opname)->toBigDecimal()->isGreaterThan(BigDecimal::of($approvedAmount))) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, ['variance_value' => (string) OpnameVariance::totalValue($opname)],
                'Nilai selisih bertambah sejak diajukan (ada mutasi stok selama persetujuan). Tolak pengajuan ini agar opname ditinjau dan diajukan ulang.');
        }
        $journal = [];
        $total = Money::zero();

        foreach ($opname->lines as $line) {
            $row = $variances[$line->id];
            if (! OpnameVariance::hasVariance($row)) {
                continue;
            }
            /** @var Qty $delta */
            $delta = $row['variance'];
            $source = new Source(StockOpname::SOURCE_TYPE, $opname->id, $line->id);
            $note = "Opname {$opname->number}";
            if ($line->stock_batch_id === null) {
                $result = $this->ledger->adjust(new BatchKey($line->batch_no, $line->expiry_date?->toDateString(), $line->stock_bin_id),
                    $delta, $source, $line->variance_reason_id, $businessDate, $location, Product::query()->withTrashed()->findOrFail($line->product_id), $note);
                $line->forceFill(['stock_batch_id' => $result->movement()->stock_batch_id])->save();
            } else {
                $result = $this->ledger->adjust(StockBatch::query()->findOrFail($line->stock_batch_id), $delta, $source,
                    $line->variance_reason_id, $businessDate, note: $note);
            }

            $value = (string) $result->value();
            $total = $total->plus($result->value());
            $dims = ['stock_location_id' => $location->id, 'product_id' => $line->product_id];
            [$debit, $credit] = $delta->isPositive() ? ['inventory', 'inventory_variance'] : ['inventory_variance', 'inventory'];
            $journal[] = ['role' => $debit, 'debit' => $value, ...$dims];
            $journal[] = ['role' => $credit, 'credit' => $value, ...$dims];
        }

        if ($journal !== []) {
            AccountingOutbox::record(EventCode::InventoryAdjusted, new EventSource(StockOpname::SOURCE_TYPE, $opname->id), $businessDate, [
                'lines' => $journal,
                'memo' => "Opname {$opname->number}",
            ]);
        }

        $before = $opname->audited();
        $opname->forceFill([
            'status' => StockOpnameStatus::Posted,
            'variance_value' => (string) $total,
            'posted_by' => $postedBy,
            'posted_at' => now(),
        ])->save();
        $this->audit->record('stock_opname.posted', $opname, $before, $opname->audited());

        return $opname;
    }
}
