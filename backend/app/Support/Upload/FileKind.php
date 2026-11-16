<?php

namespace App\Support\Upload;

/** Jenis berkas unggahan yang diizinkan (SEC-109), ditentukan dari ISI berkas. */
enum FileKind: string
{
    case Jpg = 'jpg';
    case Png = 'png';
    case Webp = 'webp';
    case Pdf = 'pdf';

    public function mime(): string
    {
        return match ($this) {
            self::Jpg => 'image/jpeg',
            self::Png => 'image/png',
            self::Webp => 'image/webp',
            self::Pdf => 'application/pdf',
        };
    }

    public function isImage(): bool
    {
        return $this !== self::Pdf;
    }

    /** @return list<self> */
    public static function images(): array
    {
        return [self::Jpg, self::Png, self::Webp];
    }

    public function label(): string
    {
        return match ($this) {
            self::Jpg => 'JPG',
            self::Png => 'PNG',
            self::Webp => 'WebP',
            self::Pdf => 'PDF',
        };
    }
}
