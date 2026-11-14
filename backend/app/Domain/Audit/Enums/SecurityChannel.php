<?php

namespace App\Domain\Audit\Enums;

/** Asal event keamanan; nilai identik dengan ENUM `security_events.channel`. */
enum SecurityChannel: string
{
    case Web = 'web';
    case Api = 'api';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Web',
            self::Api => 'Aplikasi (API)',
            self::System => 'Sistem',
        };
    }
}
