<?php

namespace App\Support\Listing;

/**
 * Setiap daftar (`*.index`) punya satu kelas `*Listing` yang mengimplementasikan ini (BR-001, ADR-007).
 * Spec dibuat per panggilan karena default filter bisa bergantung waktu (mis. "hari ini").
 */
interface HasListing
{
    public function spec(): ListingSpec;
}
