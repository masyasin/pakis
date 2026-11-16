<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Employee\Listings\ImportProfileListing;
use App\Http\Controllers\Controller;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportMappingProfile;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Profil pemetaan impor (05 §4.7, BR-054). Profil hanya berisi pasangan judul kolom → field (tanpa
 * data pegawai), jadi dipakai bersama semua pemegang `employees.import`.
 */
class ImportProfileController extends Controller
{
    #[UsesListing(ImportProfileListing::class)]
    public function index(Request $request): Response
    {
        $result = ListingQuery::apply(ImportProfileListing::query(), new ImportProfileListing, $request)
            ->through(fn (ImportMappingProfile $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'columns' => count($p->mapping),
                'creator' => $p->creator?->name,
                'updated_at' => $p->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('import-profiles/index', $result->toInertia());
    }

    public function destroy(ImportMappingProfile $profile): RedirectResponse
    {
        abort_unless($profile->type === ImportBatch::TYPE_EMPLOYEES, 404);
        $profile->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Profil \"{$profile->name}\" dihapus."]);

        return to_route('admin.import-profiles.index');
    }
}
