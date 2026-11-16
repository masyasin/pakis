<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Pricing\Enums\PriceVersionStatus;
use App\Domain\Pricing\Models\PriceListVersion;
use Illuminate\Support\Facades\DB;

/**
 * `ActivatePriceVersions` (15 §11.1, tiap 5 menit): versi `published` yang `effective_to`-nya sudah
 * tiba (versi pengganti mulai berlaku) → `superseded`. Resolusi harga sudah benar tanpa job ini
 * (memakai rentang tanggal); job hanya merapikan status tampilan. Idempoten.
 */
final class ActivatePriceVersions
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(): int
    {
        $count = 0;
        PriceListVersion::query()
            ->where('status', PriceVersionStatus::Published)
            ->whereNotNull('effective_to')
            ->where('effective_to', '<=', now())
            ->orderBy('id')
            ->each(function (PriceListVersion $version) use (&$count) {
                DB::transaction(function () use ($version, &$count) {
                    $before = $version->audited();
                    $version->forceFill(['status' => PriceVersionStatus::Superseded])->save();
                    $this->audit->record('price_version.superseded', $version, $before, $version->audited());
                    $count++;
                });
            });

        return $count;
    }
}
