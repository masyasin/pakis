<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Inventory\Listings\SerialNumberListing;
use App\Domain\Inventory\Models\SerialNumber;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Daftar nomor seri (15 §5 `admin.serials.index`/`export`, BR-144, `stock.view`). Hanya nomor seri yang
 * sudah masuk stok, dalam scope Stok pengguna lewat lokasi batch. Tukar nomor seri (`serials.swap`)
 * di luar lingkup T-080.
 */
class SerialNumberController extends Controller
{
    #[UsesListing(SerialNumberListing::class)]
    public function index(Request $request): Response
    {
        $result = ListingQuery::apply(SerialNumberListing::scopedQuery(self::user($request)), new SerialNumberListing, $request)
            ->through(fn (SerialNumber $s) => [
                'id' => $s->id,
                'serial_no' => $s->serial_no,
                'sku' => $s->product->sku,
                'product' => $s->product->name,
                'stock_location' => $s->batch?->stockLocation->name,
                'bin' => $s->batch?->bin?->code,
                'batch_no' => $s->batch?->batch_no,
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
                'input_method' => $s->input_method->value,
                'created_at' => $s->created_at?->toIso8601String(),
            ]);

        return Inertia::render('serials/index', $result->toInertia());
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'serial_numbers', self::user($request));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
