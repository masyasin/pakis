<?php

namespace App\Domain\Inventory\Queries;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockMovement;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Grafik stok di dasbor (T-087A, PA-73): nilai stok per lokasi, tren baris mutasi masuk vs keluar
 * per hari, dan batch yang mendekati ED per bulan. Semua dalam scope Stok pengguna, hanya gudang/toko
 * (lokasi transit tidak dihitung agar mutasi antar lokasi tidak terhitung dua kali). Nilai rupiah
 * (HPP rata-rata `product_costs.avg_cost`) dihitung di SQL dan hanya dikirim untuk `stock.view_cost`.
 */
final class StockCharts
{
    private const STOCK_TYPES = [StockLocationType::Warehouse, StockLocationType::Store];

    public function __construct(private readonly DataScope $scope) {}

    /**
     * @return array{
     *     value: list<array{location_id: int, location: string, value: string}>|null,
     *     flow: list<array{date: string, in: int, out: int}>,
     *     expiry: list<array{month: string, batches: int, value: string|null}>,
     *     expiry_days: int,
     * }
     */
    public function for(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $viewCost = $user->can('stock.view_cost');

        return [
            'value' => $viewCost ? $this->valuePerLocation($user) : null,
            'flow' => $this->flow($user, $from, $to),
            'expiry' => $this->expiryPerMonth($user, $viewCost),
            'expiry_days' => self::expiryDays(),
        ];
    }

    /** @return list<array{location_id: int, location: string, value: string}> */
    private function valuePerLocation(User $user): array
    {
        $query = $this->batches($user)
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_batches.stock_location_id')
            ->leftJoin('product_costs', 'product_costs.product_id', '=', 'stock_batches.product_id')
            ->groupBy('stock_batches.stock_location_id', 'stock_locations.name')
            ->select('stock_batches.stock_location_id', 'stock_locations.name')
            ->selectRaw('CAST(ROUND(SUM(stock_batches.qty_on_hand * COALESCE(product_costs.avg_cost, 0)), 2) AS CHAR) AS total_value')
            ->orderByRaw('SUM(stock_batches.qty_on_hand * COALESCE(product_costs.avg_cost, 0)) DESC')
            ->orderBy('stock_locations.name');

        return array_values($query->toBase()->get()->map(fn (object $r) => [
            'location_id' => (int) $r->stock_location_id,
            'location' => (string) $r->name,
            'value' => (string) $r->total_value,
        ])->all());
    }

    /** @return list<array{date: string, in: int, out: int}> */
    private function flow(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = StockMovement::query()
            ->whereBetween('stock_movements.business_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('stock_movements.stock_location_id', fn ($q) => $q->select('id')->from('stock_locations')
                ->whereIn('type', array_map(fn (StockLocationType $t) => $t->value, self::STOCK_TYPES)))
            ->groupBy('stock_movements.business_date')
            ->selectRaw('stock_movements.business_date AS day')
            ->selectRaw('SUM(CASE WHEN stock_movements.qty > 0 THEN 1 ELSE 0 END) AS moves_in')
            ->selectRaw('SUM(CASE WHEN stock_movements.qty < 0 THEN 1 ELSE 0 END) AS moves_out');
        $this->scope->forStock($query, $user, 'stock_movements.stock_location_id');
        $rows = $query->toBase()->get()->keyBy(fn (object $r) => substr((string) $r->day, 0, 10));

        $out = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $r = $rows->get($day->toDateString());
            $out[] = ['date' => $day->toDateString(), 'in' => (int) ($r->moves_in ?? 0), 'out' => (int) ($r->moves_out ?? 0)];
        }

        return $out;
    }

    /** @return list<array{month: string, batches: int, value: string|null}> */
    private function expiryPerMonth(User $user, bool $viewCost): array
    {
        $today = CarbonImmutable::parse(DisplayTime::toLocal(now())->toDateString());
        $until = $today->addDays(self::expiryDays());
        $query = $this->batches($user)
            ->whereIn('stock_batches.status', [StockBatchStatus::Available->value, StockBatchStatus::Quarantine->value])
            ->whereBetween('stock_batches.expiry_date', [$today->toDateString(), $until->toDateString()])
            ->leftJoin('product_costs', 'product_costs.product_id', '=', 'stock_batches.product_id')
            ->groupByRaw("DATE_FORMAT(stock_batches.expiry_date, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(stock_batches.expiry_date, '%Y-%m') AS ym")
            ->selectRaw('COUNT(*) AS batches')
            ->selectRaw('CAST(ROUND(SUM(stock_batches.qty_on_hand * COALESCE(product_costs.avg_cost, 0)), 2) AS CHAR) AS total_value');
        $rows = $query->toBase()->get()->keyBy('ym');

        $out = [];
        for ($month = $today->startOfMonth(); $month->lte($until); $month = $month->addMonth()) {
            $r = $rows->get($month->format('Y-m'));
            $out[] = [
                'month' => $month->format('Y-m'),
                'batches' => (int) ($r->batches ?? 0),
                'value' => $viewCost ? (string) ($r->total_value ?? '0.00') : null,
            ];
        }

        return $out;
    }

    /**
     * Batch ber-stok (bukan `disposed`) di gudang/toko aktif dalam scope Stok.
     *
     * @return Builder<StockBatch>
     */
    private function batches(User $user): Builder
    {
        $query = StockBatch::query()
            ->where('stock_batches.qty_on_hand', '>', 0)
            ->where('stock_batches.status', '<>', StockBatchStatus::Disposed->value)
            ->whereIn('stock_batches.stock_location_id', fn ($q) => $q->select('id')->from('stock_locations')->where('is_active', true)
                ->whereIn('type', array_map(fn (StockLocationType $t) => $t->value, self::STOCK_TYPES)));

        return $this->scope->forStock($query, $user, 'stock_batches.stock_location_id');
    }

    /** Cakrawala grafik ED = ambang terbesar `inventory.near_expiry_days` (sama dengan widget mendekati ED). */
    private static function expiryDays(): int
    {
        $days = (array) setting('inventory.near_expiry_days');

        return $days === [] ? 0 : (int) max($days);
    }
}
