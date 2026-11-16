<?php

namespace App\Console\Commands;

use App\Support\Export\ExportFile;
use App\Support\Export\ExportRunner;
use App\Support\Export\ExportStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Berkas ekspor kedaluwarsa (04 §2.10, `retention.export_days`, T-045). `expires_at` diisi saat
 * berkas jadi (ExportRunner); setelah lewat, berkas sudah tidak bisa diunduh (404), dan job ini
 * menghapus berkas fisiknya lalu menandai baris `expired`. Baris tetap ada sebagai riwayat Unduhan.
 * Berkas ekspor yang `failed` juga dibersihkan bila masih tertinggal.
 */
#[Signature('exports:expire')]
#[Description('Hapus berkas ekspor yang melewati masa simpan dan tandai expired')]
class ExpireExports extends Command
{
    private const CHUNK = 200;

    public function handle(): int
    {
        $disk = Storage::disk(ExportRunner::DISK);
        $expired = 0;

        ExportFile::query()
            ->where(fn ($q) => $q
                ->where(fn ($r) => $r->where('status', ExportStatus::Ready)->where('expires_at', '<=', now()))
                ->orWhere(fn ($f) => $f->where('status', ExportStatus::Failed)->whereNotNull('path')))
            ->chunkById(self::CHUNK, function (Collection $files) use ($disk, &$expired) {
                /** @var Collection<int, ExportFile> $files */
                $disk->delete($files->pluck('path')->filter()->values()->all());
                foreach ($files as $file) {
                    $file->path = null;
                    if ($file->status === ExportStatus::Ready) {
                        $file->status = ExportStatus::Expired;
                        $expired++;
                    }
                    $file->save();
                }
            });

        $this->info("Ekspor kedaluwarsa: {$expired}.");

        return self::SUCCESS;
    }
}
