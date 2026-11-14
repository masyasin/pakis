<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Region\Listings\RegionListing;
use App\Domain\Region\Models\Village;
use App\Http\Controllers\Controller;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Master wilayah (05 §4.5, FR-021), hanya baca. Izin dicek middleware route `can:regions.view`. */
class RegionController extends Controller
{
    #[UsesListing(RegionListing::class)]
    public function index(Request $request): Response
    {
        $query = Village::query()->with('district.regency.province');

        $result = ListingQuery::apply($query, new RegionListing, $request)
            ->through(fn (Village $village) => [
                'code' => $village->code,
                'name' => $village->name,
                'district' => $village->district?->name,
                'regency' => $village->district?->regency?->name,
                'province' => $village->district?->regency?->province?->name,
            ]);

        return Inertia::render('regions/index', [
            ...$result->toInertia(),
            // Petunjuk impor bila tabel masih kosong (bukan karena filter).
            'imported' => fn () => Village::query()->exists(),
        ]);
    }
}
