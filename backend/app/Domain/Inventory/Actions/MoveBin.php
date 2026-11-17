<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\BinZone;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Support\OpnameLock;
use App\Support\Money\Qty;
use App\Support\Time\DisplayTime;
use Illuminate\Validation\ValidationException;

/**
 * Pindah rak dalam satu lokasi stok (15 §5 `admin.stock.bin-move`): `bin_move_out`/`bin_move_in`,
 * tanpa event (15 §11.4). Masuk rak karantina = karantina manual (wajib alasan); keluar dari rak
 * karantina hanya lewat lepas karantina berapproval (BR-164), jadi keduanya ditolak di sini.
 */
final class MoveBin
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StockLedger $ledger,
    ) {}

    public function handle(StockBatch $batch, int $binId, Qty $qty, int $userId): StockBatch
    {
        return StockLedger::transaction(function () use ($batch, $binId, $qty, $userId) {
            $batch = StockBatch::query()->with('bin')->findOrFail($batch->id);
            if ($batch->status === StockBatchStatus::Disposed || $batch->bin?->zone === BinZone::Quarantine) {
                throw ValidationException::withMessages(['stock_batch_id' => 'Batch di rak karantina hanya bisa dipindah lewat lepas karantina.']);
            }
            $bin = StockBin::query()->whereKey($binId)->where('stock_location_id', $batch->stock_location_id)->where('is_active', true)->first();
            if ($bin === null || $bin->zone === BinZone::Quarantine) {
                throw ValidationException::withMessages(['stock_bin_id' => 'Pilih rak aktif non-karantina di lokasi yang sama. Untuk karantina, pakai aksi Karantina.']);
            }
            OpnameLock::assertFree([$batch->id]);

            $date = DisplayTime::toLocal(now())->toDateString();
            $result = $this->ledger->move($batch, $bin, $qty, new Source('stock_bin_move', $batch->id), MovementType::BinMoveOut, MovementType::BinMoveIn, $date);
            $dest = StockBatch::query()->findOrFail($result->movements[1]->stock_batch_id);
            $this->audit->record('stock.bin_moved', $dest, null, ['from_batch_id' => $batch->id, 'qty_base' => (string) $qty, 'stock_bin_id' => $bin->id, 'by' => $userId]);

            return $dest;
        });
    }
}
