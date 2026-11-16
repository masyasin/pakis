<?php

namespace App\Console\Commands;

use App\Domain\Pricing\Actions\ActivatePriceVersions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Rapikan status versi daftar harga yang sudah digantikan (15 §11.1). Tiap 5 menit, idempoten. */
#[Signature('pricing:activate-versions')]
#[Description('Tandai versi daftar harga yang sudah digantikan versi baru sebagai superseded')]
class ActivatePriceVersionsCommand extends Command
{
    public function handle(ActivatePriceVersions $activate): int
    {
        $count = $activate->handle();
        Log::info('pricing:activate-versions', ['superseded' => $count]);
        $this->info("Versi digantikan: {$count}");

        return self::SUCCESS;
    }
}
