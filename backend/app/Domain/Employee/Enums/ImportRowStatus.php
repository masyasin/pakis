<?php

namespace App\Domain\Employee\Enums;

/**
 * Status baris impor; nilai identik dengan ENUM `import_rows.status`. T-027 hanya menghasilkan
 * `valid`/`invalid`; `created`/`updated`/`skipped` diisi saat proses (T-028).
 */
enum ImportRowStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Created = 'created';
    case Updated = 'updated';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Valid',
            self::Invalid => 'Galat',
            self::Created => 'Dibuat',
            self::Updated => 'Diperbarui',
            self::Skipped => 'Dilewati',
        };
    }
}
