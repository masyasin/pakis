<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Fleet\Models\Vehicle;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mutasi antar lokasi (15 §4.10, FR-A27, BR-161). Draf → kirim (asal → TRANSIT) → terima per baris
 * (TRANSIT → tujuan & rak) → selisih lewat approval `stock_transfer_variance`. Asal & tujuan hanya
 * gudang/toko (tujuan kendaraan = muat canvas Paket C). `vehicle_id` hanya informasi pengangkut.
 *
 * @property int $id
 * @property string $number
 * @property int $from_stock_location_id
 * @property int $to_stock_location_id
 * @property StockTransferStatus $status
 * @property int|null $vehicle_id
 * @property int|null $shipped_by
 * @property Carbon|null $shipped_at
 * @property int|null $received_by
 * @property Carbon|null $received_at
 * @property int|null $approval_request_id
 * @property string|null $note
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockLocation $fromLocation
 * @property-read StockLocation $toLocation
 * @property-read Vehicle|null $vehicle
 * @property-read User|null $shipper
 * @property-read User|null $receiver
 * @property-read User|null $creator
 * @property-read ApprovalRequest|null $approvalRequest
 * @property-read Collection<int, StockTransferLine> $lines
 */
class StockTransfer extends Model
{
    /** `source_type` buku stok & reservasi + `source_type` outbox akuntansi (15 §4.8). */
    public const SOURCE_TYPE = 'stock_transfer';

    protected $table = 'stock_transfers';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'from_stock_location_id' => 'integer',
            'to_stock_location_id' => 'integer',
            'status' => StockTransferStatus::class,
            'vehicle_id' => 'integer',
            'shipped_by' => 'integer',
            'shipped_at' => 'datetime',
            'received_by' => 'integer',
            'received_at' => 'datetime',
            'approval_request_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'from_stock_location_id')->withTrashed();
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'to_stock_location_id')->withTrashed();
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function shipper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<ApprovalRequest, $this> */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    /** @return HasMany<StockTransferLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class, 'stock_transfer_id')->orderBy('id');
    }

    /**
     * Baris selisih yang masih menunggu keputusan/hitung ulang (terima < kirim). Dipakai approval
     * selisih dan penerimaan ulang (Keputusan 2 rencana T-082, PA-66).
     *
     * @return Collection<int, StockTransferLine>
     */
    public function varianceLines(): Collection
    {
        return $this->lines->filter(fn (StockTransferLine $line) => $line->variance()->isPositive())->values();
    }

    /**
     * Bisa diterima: kiriman pertama (`shipped`), atau hitung ulang setelah selisih ditolak/ditarik
     * (`received_with_variance` tanpa permintaan approval aktif, Keputusan 2 rencana T-082, PA-66).
     */
    public function acceptsReceipt(): bool
    {
        return $this->status === StockTransferStatus::Shipped
            || ($this->status === StockTransferStatus::ReceivedWithVariance && $this->approval_request_id === null);
    }

    /** Lewat ambang `inventory.transfer_receive_reminder_hours` tanpa diterima (15 §7.4, BR-161). */
    public function isOverdue(): bool
    {
        return $this->status->awaitingReceipt() && $this->shipped_at !== null
            && $this->shipped_at->lte(now()->subHours((int) setting('inventory.transfer_receive_reminder_hours')));
    }

    /** @return array<string, mixed> */
    public function audited(): array
    {
        return [
            'number' => $this->number,
            'from_stock_location_id' => $this->from_stock_location_id,
            'to_stock_location_id' => $this->to_stock_location_id,
            'status' => $this->status->value,
            'vehicle_id' => $this->vehicle_id,
            'shipped_by' => $this->shipped_by,
            'received_by' => $this->received_by,
            'approval_request_id' => $this->approval_request_id,
            'note' => $this->note,
        ];
    }
}
