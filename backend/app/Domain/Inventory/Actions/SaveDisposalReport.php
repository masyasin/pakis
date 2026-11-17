<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\DisposalMethod;
use App\Domain\Inventory\Enums\DisposalReportStatus;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Models\DisposalReport;
use App\Domain\Inventory\Models\DisposalReportLine;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Support\OpnameLock;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Support\Money\Money;
use App\Support\Money\Qty;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buat berita acara draf (BR-163): baris = batch `quarantine`/`expired` saja di lokasi itu, qty ≤
 * tersedia. Biaya satuan = HPP rata-rata saat disimpan (dibaca `product_costs`; CostCalculator khusus
 * Ledger) → `total_cost` = nominal approval `stock_disposal` & nilai di cetakan BA. Nomor
 * `BAP-{YYYY}-{SEQ:4}` dari NumberGenerator. Batch yang sedang di opname aktif ditolak.
 */
final class SaveDisposalReport
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NumberGenerator $numbers,
    ) {}

    /**
     * @param  array{stock_location_id: int, reason_id: int, method: DisposalMethod, planned_date: string|null, note: string|null}  $data
     * @param  list<array{stock_batch_id: int, qty_base: string}>  $lines
     */
    public function handle(array $data, array $lines, int $userId): DisposalReport
    {
        return DB::transaction(function () use ($data, $lines, $userId) {
            if (! ReferenceItem::query()->forGroup(ReferenceGroup::DisposalReason)->where('is_active', true)->whereKey($data['reason_id'])->exists()) {
                throw ValidationException::withMessages(['reason_id' => 'Pilih alasan pemusnahan.']);
            }
            $batches = StockBatch::query()->whereIn('id', array_column($lines, 'stock_batch_id'))->get()->keyBy('id');
            OpnameLock::assertFree(array_values(array_map('intval', $batches->keys()->all())));
            $costs = ProductCost::query()->whereIn('product_id', $batches->pluck('product_id')->unique())->get()->keyBy('product_id');

            $built = [];
            $total = BigDecimal::zero();
            foreach ($lines as $i => $row) {
                $batch = $batches->get($row['stock_batch_id']);
                if ($batch === null || $batch->stock_location_id !== $data['stock_location_id']) {
                    throw ValidationException::withMessages(["lines.{$i}.stock_batch_id" => 'Batch tidak ada di lokasi ini.']);
                }
                if (! in_array($batch->status, [StockBatchStatus::Quarantine, StockBatchStatus::Expired], true)) {
                    throw ValidationException::withMessages(["lines.{$i}.stock_batch_id" => 'Hanya batch karantina atau kedaluwarsa yang bisa masuk berita acara.']);
                }
                $qty = Qty::of($row['qty_base']);
                if (! $qty->isPositive() || $qty->compareTo($batch->available()) > 0) {
                    throw ValidationException::withMessages(["lines.{$i}.qty_base" => "Jumlah maksimal {$batch->available()}."]);
                }
                $cost = $costs->get($batch->product_id)?->avgCost()->toBigDecimal() ?? BigDecimal::zero();
                $total = $total->plus($qty->toBigDecimal()->multipliedBy($cost));
                $built[] = ['stock_batch_id' => $batch->id, 'product_id' => $batch->product_id, 'qty_base' => (string) $qty, 'unit_cost' => (string) $cost->toScale(4, RoundingMode::HalfUp)];
            }

            $report = new DisposalReport;
            $report->forceFill([
                ...$data,
                'number' => $this->numbers->next(DisposalReport::SOURCE_TYPE, isTaken: fn (string $n) => DisposalReport::query()->where('number', $n)->exists()),
                'status' => DisposalReportStatus::Draft,
                'total_cost' => (string) Money::of($total),
                'created_by' => $userId,
                'updated_by' => $userId,
            ])->save();
            foreach ($built as $row) {
                (new DisposalReportLine)->forceFill([...$row, 'disposal_report_id' => $report->id])->save();
            }
            $this->audit->record('disposal_report.created', $report, null, [...$report->audited(), 'lines' => count($built)]);

            return $report;
        });
    }
}
