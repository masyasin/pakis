<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\SaveStockLevel;
use App\Domain\Inventory\Listings\StockLevelListing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockLevelRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stok minimum/maksimum per produk per lokasi stok (15 §5 `admin.stock-levels.index` / `upsert`,
 * listing `stock_levels`). Baris = produk aktif; filter lokasi (wajib, dalam scope Stok) menggabungkan
 * batas & saldo lokasi itu. Simpan lewat SaveStockLevel (T-085) agar impor & layar beraudit sama.
 */
class StockLevelController extends Controller
{
    #[UsesListing(StockLevelListing::class)]
    public function index(Request $request): Response
    {
        $user = self::user($request);
        $query = Product::query()->select('products.*')->where('products.status', ProductStatus::Active->value)->with('baseUnit:id,name');
        $result = ListingQuery::apply($query, new StockLevelListing, $request)
            ->through(function (Product $p) {
                $onHand = (string) ($p->getAttribute('qty_on_hand') ?? '0');
                $min = $p->getAttribute('min_qty_base');

                return [
                    'product_id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'unit' => $p->baseUnit->name,
                    'qty_on_hand' => $onHand,
                    'min_qty_base' => $min === null ? null : (string) $min,
                    'max_qty_base' => $p->getAttribute('max_qty_base') === null ? null : (string) $p->getAttribute('max_qty_base'),
                    'below_min' => $min !== null && BigDecimal::of($onHand)->isLessThan((string) $min),
                ];
            });

        return Inertia::render('stock/levels', [
            ...$result->toInertia(),
            'canManage' => $user->can('stock_locations.manage'),
        ]);
    }

    public function upsert(StockLevelRequest $request, SaveStockLevel $save): RedirectResponse
    {
        $data = $request->level();
        $result = $save->handle($data['product_id'], $data['stock_location_id'], $data['min'], $data['max'], self::user($request)->id);

        return back()->with('success', $result['changed'] ? 'Stok minimum disimpan.' : 'Tidak ada perubahan.');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        return $user;
    }
}
