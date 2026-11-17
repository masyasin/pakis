<?php

namespace App\Domain\Inventory\Lookup;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Actions\NearExpiryAlerts;
use App\Domain\Inventory\Allocation\FefoAllocator;
use App\Domain\Inventory\Models\StockBatch;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `available_batches` (15 §5, 05 §4.11, T-078): dasar `BatchPicker`. Dua parameter wajib
 * lewat `rules()`: `product_id` + `stock_location_id`. Isinya = batch yang layak FEFO menurut
 * `FefoAllocator::candidates()` (query yang sama, jadi yang ditawarkan = yang bisa dialokasikan),
 * urut FEFO, dalam dimensi Stok pengguna (lokasi stok di luar scope → kosong, bukan bocor).
 *
 * `fefo_rank` = posisi batch dalam urutan FEFO penuh (1 = batch pertama), dihitung dari seluruh
 * batch layak, bukan hanya hasil pencarian: BatchPicker memperingatkan bila pengguna memilih
 * batch dengan rank > 1 (BR-156) walau batch rank 1 tidak tampil karena tersaring.
 */
final class AvailableBatchLookup extends LookupSource
{
    /** @var array<int, int> id batch → rank FEFO, diisi `query()` untuk satu request. */
    private array $ranks = [];

    private string $today = '';

    private int $nearDays = 0;

    public function authorize(User $user): bool
    {
        return $user->can('stock.view');
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'regex:/^[1-9]\d{0,18}$/'],
            'stock_location_id' => ['required', 'regex:/^[1-9]\d{0,18}$/'],
        ];
    }

    public function query(array $params): Builder
    {
        $this->today = FefoAllocator::date(null);
        $this->nearDays = NearExpiryAlerts::thresholds()[0] ?? 0;
        $productId = (int) $params['product_id'];
        $locationId = (int) $params['stock_location_id'];

        // Rank dari seluruh batch layak (puluhan baris per produk-lokasi, index sbatch_fefo_idx).
        $this->ranks = array_flip(FefoAllocator::candidates($productId, $locationId, $this->today)->pluck('stock_batches.id')->map(fn ($id) => (int) $id)->all());

        return FefoAllocator::candidates($productId, $locationId, $this->today)
            ->with(['bin:id,code', 'product:id,base_unit_id', 'product.baseUnit:id,name']);
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->prefix('batch_no');
    }

    /** Urutan FEFO sudah dipasang `FefoAllocator::candidates()`. */
    public function order(Builder $query): void {}

    public function option(Model $model): array
    {
        if (! $model instanceof StockBatch) {
            throw new LogicException('Lookup menerima model selain StockBatch.');
        }

        $expiry = $model->expiry_date?->toDateString();
        $days = $expiry === null ? null : (int) CarbonImmutable::parse($this->today)->diffInDays(CarbonImmutable::parse($expiry), false);
        $available = (string) $model->available();
        $unit = $model->product->baseUnit->name;
        $edLabel = $model->expiry_date === null ? 'Tanpa ED' : 'ED '.$model->expiry_date->translatedFormat('j M Y');

        return [
            'value' => $model->id,
            'label' => $model->batch_no,
            'hint' => "{$edLabel} · tersedia ".self::qtyLabel($available)." {$unit}",
            'batch_no' => $model->batch_no,
            'expiry_date' => $expiry,
            'days_to_expiry' => $days,
            'expiry_state' => match (true) {
                $days === null => 'none',
                $days < 0 => 'expired',
                $days <= $this->nearDays => 'near',
                default => 'ok',
            },
            'available_base' => $available,
            'unit' => $unit,
            'bin' => $model->bin?->code,
            'fefo_rank' => ($this->ranks[$model->id] ?? count($this->ranks)) + 1,
        ];
    }

    public function scope(Builder $query, User $user): void
    {
        app(DataScope::class)->forStock($query, $user, 'stock_batches.stock_location_id');
    }

    /** "1234.500" → "1.234,5" tanpa float (domain Inventory bebas float, ADR-101). */
    private static function qtyLabel(string $qty): string
    {
        [$whole, $fraction] = array_pad(explode('.', $qty), 2, '');
        $fraction = rtrim($fraction, '0');

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole).($fraction !== '' ? ','.$fraction : '');
    }
}
