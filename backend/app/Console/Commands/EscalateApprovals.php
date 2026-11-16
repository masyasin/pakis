<?php

namespace App\Console\Commands;

use App\Domain\Approval\ApprovalEngine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Eskalasi langkah approval yang lewat batas jam kalender (BR-153(6), 15 §11.1, PA-14). Tiap 15
 * menit, idempoten (langkah yang sudah `escalated` dilewati). Eskalasi hanya MENAMBAH penyetuju
 * (pemegang `escalation_permission`, atau `approvals.configure` bila kosong — PA-28) dan mengirim
 * notifikasi; tidak pernah menyetujui otomatis.
 */
#[Signature('approvals:escalate')]
#[Description('Tandai langkah persetujuan yang lewat batas waktu sebagai tereskalasi dan kirim notifikasi')]
class EscalateApprovals extends Command
{
    public function handle(ApprovalEngine $engine): int
    {
        $count = $engine->escalateDue();
        Log::info('approvals:escalate', ['escalated' => $count]);
        $this->info("Langkah tereskalasi: {$count}");

        return self::SUCCESS;
    }
}
