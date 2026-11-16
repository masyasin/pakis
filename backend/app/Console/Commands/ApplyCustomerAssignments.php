<?php

namespace App\Console\Commands;

use App\Domain\Customer\Support\AssignmentApplier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Job `ApplyScheduledAssignments` (15 §11.1, BR-167): tiap 00.05 waktu lokal menyalin penugasan
 * kios yang mulai hari ini (atau terlewat) ke cache `customers`. Idempoten.
 */
#[Signature('customers:apply-assignments')]
#[Description('Terapkan pengalihan kios bertanggal yang sudah jatuh tempo ke data kios')]
class ApplyCustomerAssignments extends Command
{
    public function handle(AssignmentApplier $applier): int
    {
        $count = $applier->applyDue();
        Log::info('customers:apply-assignments', ['applied' => $count]);
        $this->info("Penugasan kios diterapkan: {$count}");

        return self::SUCCESS;
    }
}
