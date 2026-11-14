<?php

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Event keamanan (append-only, SEC-041). Ditulis HANYA lewat SecurityLogger.
 *
 * @property int $id
 * @property Carbon $occurred_at
 * @property int|null $user_id
 * @property string|null $identifier
 * @property SecurityEventType $event
 * @property SecurityChannel $channel
 * @property string|null $ip
 * @property string|null $user_agent
 * @property array<string, mixed>|null $context
 */
class SecurityEvent extends Model
{
    protected $table = 'security_events';

    public $timestamps = false;

    /** `occurred_at` DATETIME(3): simpan milidetik agar urutan event dalam satu detik tetap benar. */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'event' => SecurityEventType::class,
            'channel' => SecurityChannel::class,
            'context' => 'array',
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
            throw new LogicException('security_events bersifat append-only: baris tidak boleh diubah.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new LogicException('security_events bersifat append-only: hapus hanya lewat audit:purge.');
    }
}
