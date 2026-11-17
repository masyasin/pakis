<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockOpnameLine;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hitung ulang satu baris oleh pengguna BERBEDA dari penghitung pertama (BR-162(4), SEC-103).
 * Boleh selama `counting` (baris lewat ambang) dan `review` (setelah selisih ditolak, PA-67).
 * Hasil hitung ulang menjadi hitung final baris itu.
 */
final class RecordRecount
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(StockOpname $opname, StockOpnameLine $line, Qty $qty, ?string $note, int $userId): StockOpnameLine
    {
        return DB::transaction(function () use ($opname, $line, $qty, $note, $userId) {
            $locked = StockOpname::query()->lockForUpdate()->findOrFail($opname->id);
            if (! in_array($locked->status, [StockOpnameStatus::Counting, StockOpnameStatus::Review], true)) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Opname ini tidak sedang menerima hitung ulang.');
            }
            $row = StockOpnameLine::query()->where('stock_opname_id', $locked->id)->lockForUpdate()->findOrFail($line->id);
            if ($row->qty_counted_base === null) {
                throw ValidationException::withMessages(['qty' => 'Baris ini belum dihitung pertama kali.']);
            }
            if ($row->counted_by === $userId) {
                throw ValidationException::withMessages(['qty' => 'Hitung ulang harus dilakukan orang lain, bukan penghitung pertama.']);
            }

            $before = ['line_id' => $row->id, 'qty_recount_base' => $row->qty_recount_base, 'recounted_by' => $row->recounted_by];
            $row->forceFill([
                'qty_recount_base' => (string) $qty,
                'recounted_by' => $userId,
                'recounted_at' => now(),
                'note' => $note ?? $row->note,
            ])->save();
            $this->audit->record('stock_opname.recounted', $locked, $before,
                ['line_id' => $row->id, 'qty_recount_base' => (string) $qty, 'recounted_by' => $userId]);

            return $row;
        });
    }
}
