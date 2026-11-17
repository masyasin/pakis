<?php

namespace App\Console\Commands;

use App\Domain\Pricing\Actions\EndPromotions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Akhiri promo yang lewat periode atau anggarannya habis (15 §11.1). Tiap 15 menit, idempoten. */
#[Signature('promotions:end')]
#[Description('Akhiri promo aktif yang lewat waktu selesai atau anggarannya habis')]
class EndPromotionsCommand extends Command
{
    public function handle(EndPromotions $end): int
    {
        $count = $end->handle();
        Log::info('promotions:end', ['ended' => $count]);
        $this->info("Promo diakhiri: {$count}");

        return self::SUCCESS;
    }
}
