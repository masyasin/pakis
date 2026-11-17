<?php

namespace App\Domain\Inventory\Queries;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Actions\NearExpiryAlerts;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;

/**
 * Widget dasbor "Stok mendekati ED" (15 §7.4, T-078): per lokasi stok dalam dimensi Stok pengguna,
 * jumlah batch & nilai (qty × HPP rata-rata) yang ED-nya ≤ tiap ambang `inventory.near_expiry_days`
 * (kumulatif: kolom 90 hari memuat yang 30 hari). Hanya batch `available` bersaldo dengan ED
 * setelah hari ini; ED hari ini/lewat sudah menjadi `expired` lewat job 00.15.
 *
 * Nilai hanya dikirim untuk pemegang `stock.view_cost`: nilai = HPP, dan `stock.view` saja tidak
 * memberi akses biaya (PA-64).
 */
final class NearExpirySummary
{
    /** Lokasi terbanyak yang ditampilkan; sisanya cukup disebut jumlahnya. */
    public const MAX_LOCATIONS = 10;

    private const BATCHES_SQL = [
        'SUM(CASE WHEN stock_batches.expiry_date <= ? THEN 1 ELSE 0 END) AS batches_0',
        'SUM(CASE WHEN stock_batches.expiry_date <= ? THEN 1 ELSE 0 END) AS batches_1',
        'SUM(CASE WHEN stock_batches.expiry_date <= ? THEN 1 ELSE 0 END) AS batches_2',
    ];

    private const VALUE_SQL = [
        'ROUND(SUM(CASE WHEN stock_batches.expiry_date <= ? THEN stock_batches.qty_on_hand * COALESCE(product_costs.avg_cost, 0) ELSE 0 END), 2) AS value_0',
        'ROUND(SUM(CASE WHEN stock_batches.expiry_date <= ? THEN stock_batches.qty_on_hand * COALESCE(product_costs.avg_cost, 0) ELSE 0 END), 2) AS value_1',
        'ROUND(SUM(CASE WHEN stock_batches.expiry_date <= ? THEN stock_batches.qty_on_hand * COALESCE(product_costs.avg_cost, 0) ELSE 0 END), 2) AS value_2',
    ];

    public function __construct(private readonly DataScope $scope) {}

    /**
     * @return array{thresholds: list<int>, show_value: bool, more: int, locations: list<array{id: int, name: string, buckets: list<array{days: int, batches: int, value: string|null}>}>}
     */
    public function for(User $user): array
    {
        // Setting dibatasi maks 3 ambang (08 §2.10); slot SQL di bawah juga 3.
        $thresholds = array_slice(NearExpiryAlerts::thresholds(), 0, count(self::BATCHES_SQL));
        $showValue = $user->can('stock.view_cost');
        if ($thresholds === []) {
            return ['thresholds' => [], 'show_value' => $showValue, 'more' => 0, 'locations' => []];
        }

        $today = CarbonImmutable::parse(DisplayTime::toLocal(now())->toDateString());
        $limits = array_map(fn (int $days) => $today->addDays($days)->toDateString(), $thresholds);

        $query = StockBatch::query()
            ->leftJoin('product_costs', 'product_costs.product_id', '=', 'stock_batches.product_id')
            ->where('stock_batches.status', StockBatchStatus::Available->value)
            ->where('stock_batches.qty_on_hand', '>', 0)
            ->where('stock_batches.expiry_date', '>', $today->toDateString())
            ->where('stock_batches.expiry_date', '<=', $limits[0])
            ->groupBy('stock_batches.stock_location_id')
            ->select('stock_batches.stock_location_id');
        // Satu pasang kolom agregat per ambang (SQL tetap per slot, tanggal lewat binding).
        foreach ($limits as $i => $limit) {
            $query->selectRaw(self::BATCHES_SQL[$i], [$limit])->selectRaw(self::VALUE_SQL[$i], [$limit]);
        }
        $this->scope->forStock($query, $user, 'stock_batches.stock_location_id');

        $rows = $query->get()->sortByDesc(fn ($row) => (int) $row->getAttribute('batches_0'))->values();
        $names = StockLocation::withTrashed()->whereIn('id', $rows->take(self::MAX_LOCATIONS)->pluck('stock_location_id'))->pluck('name', 'id');

        $locations = $rows->take(self::MAX_LOCATIONS)->map(fn ($row) => [
            'id' => (int) $row->getAttribute('stock_location_id'),
            'name' => (string) ($names[(int) $row->getAttribute('stock_location_id')] ?? '—'),
            'buckets' => array_map(fn (int $i) => [
                'days' => $thresholds[$i],
                'batches' => (int) $row->getAttribute("batches_{$i}"),
                'value' => $showValue ? (string) $row->getAttribute("value_{$i}") : null,
            ], array_keys($thresholds)),
        ])->all();

        return [
            'thresholds' => $thresholds,
            'show_value' => $showValue,
            'more' => max(0, $rows->count() - self::MAX_LOCATIONS),
            'locations' => array_values($locations),
        ];
    }
}
