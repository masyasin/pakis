<?php

namespace App\Domain\Customer\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * HP pemilik kios diganti lewat approval `customer_phone_change` (15 §11.2, BR-166(4), SEC-105).
 * Dikirim setelah commit; pendengar SendCustomerPhoneChangedNotification memberi tahu pemegang
 * `customers.change_phone_approve`. Nomor dibawa utuh, pendengar wajib memaskingnya sebelum tampil.
 */
final class CustomerPhoneChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $customerId,
        public readonly string $previousPhone,
        public readonly string $newPhone,
        public readonly ?int $approvedBy,
    ) {}
}
