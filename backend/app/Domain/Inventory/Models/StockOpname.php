<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Inventory\Enums\StockOpnameScope;
use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock opname (15 §4.10, FR-A28, BR-162). Draf → mulai (snapshot `frozen_at`) → hitung (blind:
 * kolom sistem tidak dikirim ke klien, SEC-103) → tinjau → posting penyesuaian (selisih lewat
 * approval `stock_opname_variance` berdasarkan nilai). Ditolak → kembali `review` untuk hitung ulang.
 *
 * @property int $id
 * @property string $number
 * @property int $stock_location_id
 * @property StockOpnameScope $scope
 * @property list<int>|null $scope_ids
 * @property bool $blind_count
 * @property StockOpnameStatus $status
 * @property Carbon|null $frozen_at
 * @property int|null $approval_request_id
 * @property string|null $variance_value
 * @property int|null $posted_by
 * @property Carbon|null $posted_at
 * @property string|null $note
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockLocation $stockLocation
 * @property-read User|null $creator
 * @property-read User|null $poster
 * @property-read ApprovalRequest|null $approvalRequest
 * @property-read Collection<int, StockOpnameLine> $lines
 */
class StockOpname extends Model
{
    /** `source_type` buku stok + outbox akuntansi (15 §4.8). */
    public const SOURCE_TYPE = 'stock_opname';

    protected $table = 'stock_opnames';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'stock_location_id' => 'integer',
            'scope' => StockOpnameScope::class,
            'scope_ids' => 'array',
            'blind_count' => 'boolean',
            'status' => StockOpnameStatus::class,
            'frozen_at' => 'datetime',
            'approval_request_id' => 'integer',
            'posted_by' => 'integer',
            'posted_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /** @return BelongsTo<ApprovalRequest, $this> */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    /** @return HasMany<StockOpnameLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockOpnameLine::class, 'stock_opname_id')->orderBy('id');
    }

    /**
     * Kolom sistem & selisih disembunyikan dari klien (SEC-103): opname blind yang belum selesai
     * dihitung. Setelah `review` semua pihak boleh melihatnya untuk menulis alasan.
     */
    public function hidesSystemQty(): bool
    {
        return $this->blind_count && in_array($this->status, [StockOpnameStatus::Draft, StockOpnameStatus::Counting], true);
    }

    /** `frozen_at` dengan milidetik untuk membandingkan `stock_movements.moved_at` DATETIME(3). */
    public function frozenAtSql(): ?string
    {
        return $this->frozen_at?->format('Y-m-d H:i:s.v');
    }

    /** @return array<string, mixed> */
    public function audited(): array
    {
        return [
            'number' => $this->number,
            'stock_location_id' => $this->stock_location_id,
            'scope' => $this->scope->value,
            'scope_ids' => $this->scope_ids,
            'blind_count' => $this->blind_count,
            'status' => $this->status->value,
            'frozen_at' => $this->frozenAtSql(),
            'approval_request_id' => $this->approval_request_id,
            'variance_value' => $this->variance_value,
            'posted_by' => $this->posted_by,
            'note' => $this->note,
        ];
    }
}
