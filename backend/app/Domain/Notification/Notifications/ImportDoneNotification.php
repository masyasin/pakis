<?php

namespace App\Domain\Notification\Notifications;

use App\Domain\Notification\Enums\NotificationKind;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportRegistry;
use App\Support\Import\ImportStatus;

/**
 * Impor selesai/gagal (BR-070, judul memakai label tipe impor) → pengunggah. Hanya jumlah baris dan nama berkas: tidak ada
 * data pegawai di isi notifikasi (bisa terkirim lewat email/Telegram).
 */
class ImportDoneNotification extends PakisNotification
{
    private readonly bool $failed;

    private readonly string $name;

    /** Nama data tipe impor, huruf kecil ("pegawai"). */
    private readonly string $label;

    /** @var array{created: int, updated: int, skipped: int, errors: int} */
    private readonly array $counts;

    private readonly int $batchId;

    // Nilai disalin saat dibuat: notifikasi diantrekan, isinya harus sesuai saat proses berakhir.
    public function __construct(ImportBatch $batch)
    {
        $this->batchId = $batch->id;
        $this->failed = $batch->status === ImportStatus::Failed;
        $this->name = $batch->original_name;
        $this->label = ImportRegistry::has($batch->type) ? mb_strtolower(ImportRegistry::forBatch($batch)->label()) : 'data';
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
        return $this->failed ? "Impor {$this->label} gagal" : "Impor {$this->label} selesai";
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
