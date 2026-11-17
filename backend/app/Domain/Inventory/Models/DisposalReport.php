<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Inventory\Enums\DisposalMethod;
use App\Domain\Inventory\Enums\DisposalReportStatus;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Berita acara pemusnahan / serah (15 §4.10, FR-A29, BR-163). Draf (batch karantina/ED) → ajukan →
 * approval `stock_disposal` (nominal HPP) → disetujui (batch direservasi, PA-69) → cetak BA →
 * laksanakan dengan scan BA + foto + ≥ 2 saksi tanpa NIK.
 *
 * @property int $id
 * @property string $number
 * @property int $stock_location_id
 * @property int $reason_id
 * @property DisposalMethod $method
 * @property DisposalReportStatus $status
 * @property Carbon|null $planned_date
 * @property Carbon|null $executed_at
 * @property string|null $executed_location
 * @property list<array{name: string, role: string, organization: string}>|null $witnesses
 * @property string|null $document_path
 * @property list<string>|null $photo_paths
 * @property string $total_cost
 * @property int|null $approval_request_id
 * @property string|null $note
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockLocation $stockLocation
 * @property-read ReferenceItem $reason
 * @property-read User|null $creator
 * @property-read ApprovalRequest|null $approvalRequest
 * @property-read Collection<int, DisposalReportLine> $lines
 */
class DisposalReport extends Model
{
    /** `source_type` buku stok, reservasi & outbox (15 §4.8). */
    public const SOURCE_TYPE = 'disposal_report';

    protected $table = 'disposal_reports';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'stock_location_id' => 'integer',
            'reason_id' => 'integer',
            'method' => DisposalMethod::class,
            'status' => DisposalReportStatus::class,
            'planned_date' => 'date',
            'executed_at' => 'datetime',
            'witnesses' => 'array',
            'photo_paths' => 'array',
            'approval_request_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id')->withTrashed();
    }

    /** @return BelongsTo<ReferenceItem, $this> */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'reason_id');
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

    /** @return HasMany<DisposalReportLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DisposalReportLine::class, 'disposal_report_id')->orderBy('id');
    }

    /** @return array<string, mixed> */
    public function audited(): array
    {
        return [
            'number' => $this->number,
            'stock_location_id' => $this->stock_location_id,
            'reason_id' => $this->reason_id,
            'method' => $this->method->value,
            'status' => $this->status->value,
            'planned_date' => $this->planned_date?->toDateString(),
            'executed_location' => $this->executed_location,
            // Saksi tanpa NIK (BR-163); nama & jabatan boleh dicatat audit.
            'witnesses' => $this->witnesses,
            'total_cost' => $this->total_cost,
            'approval_request_id' => $this->approval_request_id,
            'note' => $this->note,
        ];
    }
}
