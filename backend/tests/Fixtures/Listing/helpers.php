<?php

use App\Support\Listing\ListingQuery;
use App\Support\Listing\ListingResult;
use Illuminate\Http\Request;
use Tests\Fixtures\Listing\ListingTestUser;
use Tests\Fixtures\Listing\UserTestListing;

/**
 * Jalankan listing uji dengan query string seperti dari browser.
 *
 * @param  array<string, mixed>  $query
 */
function runListing(array $query = [], string $path = '/admin/uji'): ListingResult
{
    $request = Request::create($path, 'GET', $query);

    return ListingQuery::apply(ListingTestUser::query(), new UserTestListing, $request);
}

/**
 * @param  array<string, mixed>  $query
 * @return list<string>
 */
function listedNames(array $query = []): array
{
    return collect(runListing($query)->paginator()->items())->pluck('name')->all();
}
