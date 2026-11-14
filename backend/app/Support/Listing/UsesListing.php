<?php

namespace App\Support\Listing;

use Attribute;

/**
 * Penanda di method controller `index` bahwa route memakai standar listing.
 * Dibaca oleh tests/Contract/ListingContractTest (T-005): route `*.index` tanpa atribut ini membuat CI merah.
 *
 *     #[UsesListing(EmployeeListing::class)]
 *     public function index(Request $request): Response
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class UsesListing
{
    /**
     * @param  class-string<HasListing>  $listing
     */
    public function __construct(public readonly string $listing) {}
}
