<?php

namespace App\Support\Export\Events;

use App\Support\Export\ExportFile;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Ekspor diminta. T-007 mendengarkan ini untuk audit `export.created` dan, bila
 * `rowCount` > export.mass_export_alert_rows, security event `mass_export` (BR-005, SEC-051).
 */
final class ExportCreated
{
    use Dispatchable;

    public function __construct(
        public readonly ExportFile $file,
        public readonly int $rowCount,
    ) {}
}
