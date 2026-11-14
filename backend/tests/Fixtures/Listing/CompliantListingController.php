<?php

namespace Tests\Fixtures\Listing;

use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Controller daftar yang patuh standar, untuk meta-test ListingContractTest. */
class CompliantListingController
{
    #[UsesListing(UserTestListing::class)]
    public function index(Request $request): Response
    {
        $result = ListingQuery::apply(ListingTestUser::query(), new UserTestListing, $request)
            ->through(fn (ListingTestUser $u) => ['id' => $u->id, 'name' => $u->name]);

        return Inertia::render('dev/listing-demo', $result->toInertia());
    }
}
