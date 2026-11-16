<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Pricing\Enums\PriceVersionStatus;
use App\Domain\Pricing\Models\PriceList;
use App\Domain\Pricing\Models\PriceListItem;
use App\Domain\Pricing\Models\PriceListVersion;
use App\Domain\Pricing\Support\PriceRounding;
use App\Support\Money\Money;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Versi baru daftar harga (FR-A19, ADR-108), selalu `draft`:
 * - `empty`        : tanpa item;
 * - `copy`         : salin semua item versi sumber (boleh dari list lain);
 * - `copy_percent` : salin dengan harga × (100 ± persen)%, dibulatkan `pricing.rounding`.
 * Nomor versi naik per list (list dikunci agar dua pengguna tidak mendapat nomor sama).
 * `effective_from` diisi waktu LOKAL kantor lalu disimpan UTC.
 */
final class CreatePriceVersion
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data  sudah divalidasi PriceVersionRequest */
    public function handle(PriceList $list, array $data, ?int $userId): PriceListVersion
    {
        return DB::transaction(function () use ($list, $data, $userId) {
            PriceList::query()->lockForUpdate()->findOrFail($list->id);
            $mode = (string) $data['mode'];
            $sourceId = $mode === 'empty' ? null : (int) $data['source_version_id'];

            $version = new PriceListVersion;
            $version->forceFill([
                'price_list_id' => $list->id,
                'version_no' => (int) PriceListVersion::query()->where('price_list_id', $list->id)->max('version_no') + 1,
                'effective_from' => CarbonImmutable::parse((string) $data['effective_from'], DisplayTime::timezone())->utc(),
                'status' => PriceVersionStatus::Draft,
                'note' => $data['note'] ?? null,
                'copied_from_version_id' => $sourceId,
                'created_by' => $userId,
                'updated_by' => $userId,
            ])->save();

            $copied = 0;
            if ($sourceId !== null) {
                $percent = $mode === 'copy_percent' ? (string) $data['percent'] : null;
                PriceListItem::query()->where('price_list_version_id', $sourceId)->orderBy('id')
                    ->chunk(500, function ($items) use ($version, $percent, &$copied) {
                        $rows = [];
                        foreach ($items as $item) {
                            /** @var PriceListItem $item */
                            $price = Money::of($item->price);
                            $rows[] = [
                                'price_list_version_id' => $version->id,
                                'product_id' => $item->product_id,
                                'product_unit_id' => $item->product_unit_id,
                                'min_qty' => $item->min_qty,
                                'price' => (string) ($percent === null ? $price : PriceRounding::adjust($price, $percent)),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }
                        DB::table('price_list_items')->insert($rows);
                        $copied += count($rows);
                    });
            }

            $this->audit->record('price_version.created', $version, null, [
                ...$version->audited(),
                'mode' => $mode,
                'copied_from_version_id' => $sourceId,
                'percent' => $mode === 'copy_percent' ? (string) $data['percent'] : null,
                'items' => $copied,
            ]);

            return $version;
        });
    }
}
