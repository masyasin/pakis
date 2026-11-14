<?php

namespace Tests\Fixtures\Listing;

use Illuminate\Http\JsonResponse;

/** Daftar "buatan sendiri" tanpa #[UsesListing]: harus ditolak ListingContractTest. */
class NonCompliantListingController
{
    public function index(): JsonResponse
    {
        return response()->json(ListingTestUser::query()->get());
    }
}
