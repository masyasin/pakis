<?php

namespace App\Domain\Notification\Notifications;

use App\Domain\Employee\Enums\ImportStatus;
use App\Domain\Employee\Models\ImportBatch;
use App\Domain\Notification\Enums\NotificationKind;

/**
 * Impor pegawai selesai/gagal (BR-070) → pengunggah. Hanya jumlah baris dan nama berkas: tidak ada
 * data pegawai di isi notifikasi (bisa terkirim lewat email/Telegram).
 */
class ImportDoneNotification extends PakisNotification
{
    private readonly bool $failed;

    private readonly string $name;

    /** @var array{created: int, updated: int, skipped: int, errors: int} */
    private readonly array $counts;

    private readonly int $batchId;

    // Nilai disalin saat dibuat: notifikasi diantrekan, isinya harus sesuai saat proses berakhir.
    public function __construct(ImportBatch $batch)
    {
        $this->batchId = $batch->id;
        $this->failed = $batch->status === ImportStatus::Failed;
        $this->name = $batch->original_name;
        $this->counts = [
            'created' => $batch->created_rows,
            'updated' => $batch->updated_rows,
            'skipped' => $batch->skipped_rows,
            'errors' => $batch->error_rows,
        ];
    }

    public function kind(): NotificationKind
    {
        return NotificationKind::ImportDone;
    }

    public function title(): string
    {
        return $this->failed ? 'Impor pegawai gagal' : 'Impor pegawai selesai';
    }

    public function body(): string
    {
        $n = fn (int $v) => number_format($v, 0, ',', '.');
        $summary = sprintf(
            '%s dibuat, %s diperbarui, %s dilewati, %s galat.',
            $n($this->counts['created']), $n($this->counts['updated']), $n($this->counts['skipped']), $n($this->counts['errors']),
        );

        return $this->failed
            ? "Impor \"{$this->name}\" berhenti di tengah jalan. Baris yang sudah diproses tetap tersimpan: {$summary}"
            : "Impor \"{$this->name}\" selesai: {$summary}";
    }

    public function url(): ?string
    {
        return "/impor/{$this->batchId}/baris";
    }
}
