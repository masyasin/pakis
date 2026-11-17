<?php

namespace App\Domain\Customer\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Kios selesai diverifikasi & aktif (15 §11.2, BR-165). Dikirim setelah transaksi persetujuan
 * commit; belum ada pendengar di Paket A (Paket B: kunjungan/order kredit).
 */
final class CustomerActivated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $customerId,
        public readonly int $verificationId,
        public readonly ?int $approvedBy,
    ) {}
}
