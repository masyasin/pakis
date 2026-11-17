<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\QuarantineReleaseStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permintaan lepas karantina (`stock_quarantine_releases`, BR-164, tabel baru keputusan Mas PA-69):
 * qty dari batch karantina ke rak non-karantina pilihan, menunggu approval `stock_quarantine_release`.
 *
 * @property int $id
 * @property int $stock_batch_id
 * @property string $qty_base
 * @property int $target_bin_id
 * @property string $reason
 * @property QuarantineReleaseStatus $status
 * @property int|null $released_batch_id
 * @property int|null $approval_request_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockBatch $batch
 * @property-read StockBin $targetBin
 * @property-read User|null $creator
 */
class QuarantineRelease extends Model
{
    public const SOURCE_TYPE = 'stock_quarantine_release';

    protected $table = 'stock_quarantine_releases';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'stock_batch_id' => 'integer',
            'target_bin_id' => 'integer',
            'status' => QuarantineReleaseStatus::class,
            'released_batch_id' => 'integer',
            'approval_request_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    /** @return BelongsTo<StockBin, $this> */
    public function targetBin(): BelongsTo
    {
        return $this->belongsTo(StockBin::class, 'target_bin_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, mixed> */
    public function audited(): array
    {
        return [
            'stock_batch_id' => $this->stock_batch_id,
            'qty_base' => $this->qty_base,
            'target_bin_id' => $this->target_bin_id,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'released_batch_id' => $this->released_batch_id,
            'approval_request_id' => $this->approval_request_id,
        ];
    }
}
