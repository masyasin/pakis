<?php

namespace App\Domain\Inventory\Lookup;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Models\StockBatch;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `returnable_batches` (05 §4.11, T-081): batch yang bisa diretur ke prinsipal untuk
 * `BatchPicker` mode retur. Beda dari `available_batches` (FEFO, hanya `available`): batch
 * `available`, `quarantine`, dan `expired` ikut (retur justru mengeluarkan barang karantina/ED,
 * BR-163, PA-63/PA-68), qty tersedia > 0, urut ED terdekat. Dimensi Stok pengguna. `fefo_rank`
 * selalu 1 agar BatchPicker tidak memunculkan peringatan FEFO (retur bukan alokasi jual).
 */
final class ReturnableBatchLookup extends LookupSource
{
    private string $today = '';

    public function authorize(User $user): bool
    {
        return $user->can('purchase_returns.manage');
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
        $this->today = DisplayTime::toLocal(now())->toDateString();

        return StockBatch::query()
            ->with(['bin:id,code', 'product:id,base_unit_id', 'product.baseUnit:id,name'])
            ->where('stock_batches.product_id', (int) $params['product_id'])
            ->where('stock_batches.stock_location_id', (int) $params['stock_location_id'])
            ->whereIn('stock_batches.status', [StockBatchStatus::Available->value, StockBatchStatus::Quarantine->value, StockBatchStatus::Expired->value])
            ->whereColumn('stock_batches.qty_on_hand', '>', 'stock_batches.qty_reserved');
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->prefix('batch_no');
    }

    public function order(Builder $query): void
    {
        // SQL tetap (bukan input): ED kosong terakhir.
        $query->orderByRaw('stock_batches.expiry_date IS NULL')->orderBy('stock_batches.expiry_date')->orderBy('stock_batches.id');
    }

    public function option(Model $model): array
    {
        if (! $model instanceof StockBatch) {
            throw new LogicException('Lookup menerima model selain StockBatch.');
        }

        $expiry = $model->expiry_date?->toDateString();
        $days = $expiry === null ? null : (int) CarbonImmutable::parse($this->today)->diffInDays(CarbonImmutable::parse($expiry), false);
        $available = (string) $model->available();
        $unit = $model->product->baseUnit->name;

        return [
            'value' => $model->id,
            'label' => $model->batch_no,
            'hint' => $model->status->label().' · '.($model->expiry_date === null ? 'Tanpa ED' : 'ED '.$model->expiry_date->translatedFormat('j M Y')),
            'batch_no' => $model->batch_no,
            'expiry_date' => $expiry,
            'days_to_expiry' => $days,
            'expiry_state' => match (true) {
                $days === null => 'none',
                $days < 0 => 'expired',
                default => 'ok',
            },
            'available_base' => $available,
            'unit' => $unit,
            'bin' => $model->bin?->code,
            'status' => $model->status->value,
            'fefo_rank' => 1,
        ];
    }

    public function scope(Builder $query, User $user): void
    {
        app(DataScope::class)->forStock($query, $user, 'stock_batches.stock_location_id');
    }
}
