<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Support\Export\Events\ExportCreated;
use App\Support\Export\ExportRegistry;

/**
 * Setiap ekspor dicatat di audit (`export.created`, 09 §7.1). Ekspor besar juga menjadi event
 * keamanan `mass_export` (BR-005, SEC-051) — sinyal awal kebocoran data massal.
 */
final class RecordExportCreated
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecurityLogger $security,
    ) {}

    public function handle(ExportCreated $event): void
    {
        $file = $event->file;

        $this->audit->record('export.created', $file, null, [
            'listing' => $file->listing,
            'row_count' => $event->rowCount,
            'params' => $file->params,
        ]);

        if ($event->rowCount > ExportRegistry::massExportAlertRows()) {
            $this->security->record(SecurityEventType::MassExport, SecurityChannel::Web, context: [
                'listing' => $file->listing,
                'row_count' => $event->rowCount,
                'export_file_id' => $file->id,
            ]);
        }
    }
}
