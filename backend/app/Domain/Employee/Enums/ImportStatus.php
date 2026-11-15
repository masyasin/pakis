<?php

namespace App\Domain\Employee\Enums;

/**
 * Status batch impor (06 §7.4); nilai identik dengan ENUM `import_batches.status`.
 * `uploaded` → `mapped` (pemetaan disimpan, validasi berjalan) → `validated` → `processing` →
 * `completed`/`failed`. Dari `uploaded`/`mapped`/`validated` bisa `cancelled`.
 */
enum ImportStatus: string
{
    case Uploaded = 'uploaded';
    case Mapped = 'mapped';
    case Validated = 'validated';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Diunggah',
            self::Mapped => 'Divalidasi…',
            self::Validated => 'Siap diproses',
            self::Processing => 'Diproses',
            self::Completed => 'Selesai',
            self::Failed => 'Gagal',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /** Pemetaan boleh disimpan ulang (validasi diulang) selama belum diproses. */
    public function canRemap(): bool
    {
        return in_array($this, [self::Uploaded, self::Mapped, self::Validated], true);
    }

    public function canCancel(): bool
    {
        return $this->canRemap();
    }
}
