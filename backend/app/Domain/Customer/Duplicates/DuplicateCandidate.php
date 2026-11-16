<?php

namespace App\Domain\Customer\Duplicates;

use App\Domain\Customer\Models\Customer;

/** Satu kandidat duplikat (BR-166(2)) beserta alasannya. */
final readonly class DuplicateCandidate
{
    /**
     * @param  list<string>  $reasons  `name_nearby` (nama mirip dalam radius), `name_same_village`, `phone`
     */
    public function __construct(
        public Customer $customer,
        public array $reasons,
        public ?float $similarity,
        public ?int $distanceM,
    ) {}
}
