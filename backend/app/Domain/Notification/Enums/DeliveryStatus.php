<?php

namespace App\Domain\Notification\Enums;

/** Hasil satu upaya kirim kanal luar; nilai identik dengan ENUM `notification_deliveries.status`. */
enum DeliveryStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
    case SkippedQuota = 'skipped_quota';
    case SkippedPaused = 'skipped_paused';
}
