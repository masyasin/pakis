<?php

namespace App\Domain\Audit;

use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\Models\SecurityEvent;
use App\Support\Api\RequestId;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Satu-satunya pintu menulis log keamanan (SEC-041). `identifier` adalah username/email yang DICOBA
 * (untuk login gagal); jangan pernah memasukkan password atau token ke `context`.
 */
final class SecurityLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        SecurityEventType $event,
        SecurityChannel $channel,
        ?Authenticatable $user = null,
        ?string $identifier = null,
        array $context = [],
    ): SecurityEvent {
        $entry = new SecurityEvent([
            'occurred_at' => now(),
            'user_id' => ($user ?? request()->user())?->getAuthIdentifier(),
            'identifier' => $identifier === null ? null : mb_substr($identifier, 0, 150),
            'event' => $event,
            'channel' => $channel,
            'ip' => request()->ip(),
            'user_agent' => AuditLogger::userAgent(request()),
            'context' => Redactor::redact([...$context, 'request_id' => RequestId::current()]),
        ]);
        $entry->save();

        return $entry;
    }
}
