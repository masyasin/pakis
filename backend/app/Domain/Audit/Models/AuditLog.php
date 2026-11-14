<?php

namespace App\Domain\Audit\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Baris audit (append-only). Ditulis HANYA lewat AuditLogger. Mengubah/menghapus baris lama
 * ditolak di sini dan oleh trigger DB (SEC-040).
 *
 * @property int $id
 * @property Carbon $occurred_at
 * @property int|null $user_id
 * @property string|null $actor_label
 * @property string $event
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $request_id
 */
class AuditLog extends Model
{
    protected $table = 'audit_logs';

    public $timestamps = false;

    /** `occurred_at` DATETIME(3): simpan milidetik agar urutan event dalam satu detik tetap benar. */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('audit_logs bersifat append-only: baris tidak boleh diubah.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new LogicException('audit_logs bersifat append-only: hapus hanya lewat audit:purge.');
    }
}
