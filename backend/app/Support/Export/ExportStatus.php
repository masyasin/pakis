<?php

namespace App\Support\Export;

/** Status berkas ekspor; nilai identik dengan ENUM `export_files.status`. */
enum ExportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Antre',
            self::Processing => 'Diproses',
            self::Ready => 'Siap',
            self::Failed => 'Gagal',
            self::Expired => 'Kedaluwarsa',
        };
    }
}
