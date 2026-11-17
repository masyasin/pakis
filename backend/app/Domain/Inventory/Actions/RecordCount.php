<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockOpnameLine;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use App\Support\Upload\SecureUpload;
use App\Support\Upload\UploadTarget;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Hitung pertama satu baris (lembar hitung, BR-162(3)) selama `counting`. Mengubah hitungan yang
 * sudah ada membatalkan hitung ulangnya (angka dasar hitung ulang berubah) dan DICATAT di audit
 * (`stock_opname.count_changed`), agar menebak-nebak angka sampai lolos ambang hitung ulang
 * terlihat oleh Owner (SEC-103, TA-01). Foto opsional lewat SecureUpload (SEC-109).
 */
final class RecordCount
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecureUpload $uploads,
    ) {}

    public function handle(StockOpname $opname, StockOpnameLine $line, Qty $qty, ?string $note, ?UploadedFile $photo, int $userId): StockOpnameLine
    {
        return DB::transaction(function () use ($opname, $line, $qty, $note, $photo, $userId) {
            $locked = StockOpname::query()->lockForUpdate()->findOrFail($opname->id);
            if ($locked->status !== StockOpnameStatus::Counting) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Lembar hitung sudah ditutup.');
            }
            $row = StockOpnameLine::query()->where('stock_opname_id', $locked->id)->lockForUpdate()->findOrFail($line->id);

            $before = $row->qty_counted_base;
            $changed = $before !== null && ! Qty::of($before)->isEqualTo($qty);
            $values = [
                'qty_counted_base' => (string) $qty,
                'counted_by' => $userId,
                'counted_at' => now(),
                'note' => $note,
            ];
            if ($changed) {
                $values += ['qty_recount_base' => null, 'recounted_by' => null, 'recounted_at' => null];
            }
            if ($photo !== null) {
                $stored = $this->uploads->store($photo, UploadTarget::privatePhoto("opname/{$locked->id}"));
                $values['photo_path'] = $stored->path;
            }
            $row->forceFill($values)->save();

            if ($changed) {
                $this->audit->record('stock_opname.count_changed', $locked, ['line_id' => $row->id, 'qty_counted_base' => $before],
                    ['line_id' => $row->id, 'qty_counted_base' => (string) $qty]);
            }

            return $row;
        });
    }
}
