<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Support\OpnameVariance;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Selesai hitung (`counting` → `review`, rute `admin.stock-opnames.submit` "/ajukan", PA-67).
 * Semua baris wajib terhitung. Baris yang selisihnya lewat ambang (BR-162(4)) wajib sudah dihitung
 * ulang orang lain; bila belum → `OPNAME_RECOUNT_REQUIRED` 422 dengan daftar baris (tanpa angka
 * sistem, SEC-103), lembar tetap terbuka untuk hitung ulang.
 */
final class FinishCounting
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(StockOpname $opname, int $userId): StockOpname
    {
        return DB::transaction(function () use ($opname, $userId) {
            $locked = StockOpname::query()->lockForUpdate()->findOrFail($opname->id);
            if ($locked->status !== StockOpnameStatus::Counting) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Opname ini tidak sedang dihitung.');
            }
            $lines = $locked->lines()->get();
            $uncounted = $lines->filter(fn ($l) => ! $l->isCounted())->count();
            if ($uncounted > 0) {
                throw ValidationException::withMessages(['lines' => "Masih ada {$uncounted} baris yang belum dihitung."]);
            }
            $locked->setRelation('lines', $lines);
            self::assertRecounted($locked, OpnameVariance::for($locked));

            $before = $locked->audited();
            $locked->forceFill(['status' => StockOpnameStatus::Review, 'updated_by' => $userId])->save();
            $this->audit->record('stock_opname.counted', $locked, $before, $locked->audited());

            return $locked;
        });
    }

    /**
     * @param  array<int, array{recount_required: bool, variance: mixed}>  $variances
     * @param  bool  $all  setelah selisih ditolak: SETIAP baris selisih wajib dihitung ulang (PA-67)
     */
    public static function assertRecounted(StockOpname $opname, array $variances, bool $all = false): void
    {
        $missing = [];
        foreach ($opname->lines as $line) {
            $row = $variances[$line->id];
            $needs = $all ? OpnameVariance::hasVariance($row) : $row['recount_required'];
            if ($needs && $line->qty_recount_base === null) {
                $missing[] = $line->id;
            }
        }
        if ($missing !== []) {
            throw new ApiError(ErrorCode::OPNAME_RECOUNT_REQUIRED, ['lines' => $missing]);
        }
    }
}
