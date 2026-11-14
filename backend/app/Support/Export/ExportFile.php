<?php

namespace App\Support\Export;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $listing
 * @property array{q?: string, sort?: string, filter?: array<string, mixed>} $params
 * @property ExportStatus $status
 * @property string|null $path
 * @property int|null $row_count
 * @property Carbon|null $expires_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ExportFile extends Model
{
    protected $table = 'export_files';

    protected $fillable = ['listing', 'params', 'status', 'path', 'row_count', 'expires_at', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'status' => ExportStatus::class,
            'expires_at' => 'datetime',
            'row_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Bisa diunduh: siap, berkas ada, dan belum lewat masa simpan. */
    public function isDownloadable(): bool
    {
        return $this->status === ExportStatus::Ready
            && $this->path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function downloadName(): string
    {
        return sprintf('%s-%s.xlsx', $this->listing, ($this->created_at ?? now())->format('Ymd-His'));
    }
}
