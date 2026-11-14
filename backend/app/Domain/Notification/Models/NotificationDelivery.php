<?php

namespace App\Domain\Notification\Models;

use App\Domain\Notification\Enums\DeliveryChannel;
use App\Domain\Notification\Enums\DeliveryStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu upaya kirim kanal luar (04 §2.14). Hanya metadata: tidak ada isi pesan, alamat, atau token.
 *
 * @property int $id
 * @property string $notification_id
 * @property int|null $user_id
 * @property string $kind
 * @property DeliveryChannel $channel
 * @property DeliveryStatus $status
 * @property DeliveryChannel|null $fallback_from
 * @property string|null $error
 * @property Carbon $created_at
 */
class NotificationDelivery extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['notification_id', 'user_id', 'kind', 'channel', 'status', 'fallback_from', 'error'];

    protected function casts(): array
    {
        return [
            'channel' => DeliveryChannel::class,
            'status' => DeliveryStatus::class,
            'fallback_from' => DeliveryChannel::class,
            'created_at' => 'datetime',
        ];
    }
}
