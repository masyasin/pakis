<?php

namespace App\Support\Export\Events;

use App\Support\Export\ExportFile;
use Illuminate\Foundation\Events\Dispatchable;

/** Ekspor antrean selesai dan siap diunduh. T-029 mengirim notifikasi ke pembuat (FR-029). */
final class ExportReady
{
    use Dispatchable;

    public function __construct(public readonly ExportFile $file) {}
}
