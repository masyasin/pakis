<?php

namespace App\Jobs;

use App\Support\Export\Events\ExportReady;
use App\Support\Export\ExportFile;
use App\Support\Export\ExportRunner;
use App\Support\Export\ExportStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Ekspor besar (> export.sync_max_rows) di antrean. Hanya ID yang diserialisasi; query dibangun
 * ulang dari parameter tersimpan + pembuat (scope ikut pembuat, bukan worker).
 */
class RunExport implements ShouldQueue
{
    use Queueable;

    /** Ekspor tidak diulang otomatis: kegagalan ditandai `failed` dan pengguna bisa mengekspor lagi. */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $exportFileId) {}

    public function handle(ExportRunner $runner): void
    {
        $file = ExportFile::query()->find($this->exportFileId);
        if ($file === null || $file->status !== ExportStatus::Queued) {
            return;
        }

        $file->forceFill(['status' => ExportStatus::Processing])->save();
        $runner->run($file);

        ExportReady::dispatch($file->refresh());
    }

    public function failed(?Throwable $exception): void
    {
        // Detail galat ada di log (dengan request_id dari Context); UI hanya menampilkan "Gagal".
        ExportFile::query()->whereKey($this->exportFileId)->update(['status' => ExportStatus::Failed->value]);
    }
}
