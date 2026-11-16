<?php

namespace App\Domain\Customer\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Kios dialihkan ke area/sales lain (15 §11.2, BR-167). Dikirim setelah transaksi commit, jadi
 * pendengar (notifikasi, Paket B: daftar kunjungan) tidak melihat pengalihan yang batal.
 */
final class CustomerReassigned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $customerIds  kios yang penugasannya benar-benar berubah
     */
    public function __construct(
        public readonly array $customerIds,
        public readonly int $salesAreaId,
        public readonly ?int $salesEmployeeId,
        public readonly string $effectiveDate,
        public readonly ?int $userId,
    ) {}
}
