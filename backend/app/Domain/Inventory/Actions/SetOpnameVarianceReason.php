<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockOpnameLine;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alasan selisih per baris (BR-162(5), referensi `opname_variance_reason`) diisi saat `review`:
 * penghitung blind baru tahu ada selisih setelah lembar ditutup, jadi alasan tidak diminta saat hitung.
 */
final class SetOpnameVarianceReason
{
    public function handle(StockOpname $opname, StockOpnameLine $line, int $reasonId, ?string $note): StockOpnameLine
    {
        return DB::transaction(function () use ($opname, $line, $reasonId, $note) {
            $locked = StockOpname::query()->lockForUpdate()->findOrFail($opname->id);
            if ($locked->status !== StockOpnameStatus::Review) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Alasan selisih diisi saat tinjau.');
            }
            $valid = ReferenceItem::query()->forGroup(ReferenceGroup::OpnameVarianceReason)->where('is_active', true)->whereKey($reasonId)->exists();
            if (! $valid) {
                throw ValidationException::withMessages(['variance_reason_id' => 'Pilih alasan selisih yang tersedia.']);
            }
            $row = StockOpnameLine::query()->where('stock_opname_id', $locked->id)->lockForUpdate()->findOrFail($line->id);
            $row->forceFill(['variance_reason_id' => $reasonId, 'note' => $note ?? $row->note])->save();

            return $row;
        });
    }
}
