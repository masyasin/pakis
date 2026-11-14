<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Listing\Demo\UserDemoListing;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Time\DisplayTime;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman uji visual DataTable (T-005). Route hanya didaftarkan di environment local.
 */
class ListingDemoController extends Controller
{
    #[UsesListing(UserDemoListing::class)]
    public function __invoke(Request $request): Response
    {
        $result = ListingQuery::apply(User::query(), new UserDemoListing, $request)
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'verified' => $user->email_verified_at !== null,
                'mfa' => $user->two_factor_confirmed_at !== null,
                'created_at' => $user->created_at
                    ? DisplayTime::toLocal($user->created_at)->translatedFormat('j M Y H.i')
                    : null,
            ]);

        return Inertia::render('dev/listing-demo', $result->toInertia());
    }
}
