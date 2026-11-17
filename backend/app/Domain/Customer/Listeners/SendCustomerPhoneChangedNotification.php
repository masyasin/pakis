<?php

namespace App\Domain\Customer\Listeners;

use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Approval\ApproverResolver;
use App\Domain\Customer\Events\CustomerPhoneChanged;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Notifications\CustomerPhoneChangedNotification;
use App\Models\User;
use App\Support\Phone\PhoneNormalizer;
use Illuminate\Support\Facades\Notification;

/**
 * Notifikasi ganti HP kios (BR-166(4), SEC-105) ke pemegang `customers.change_phone_approve` yang
 * aktif, BUKAN Super Admin vendor (semangat PA-37: vendor bukan pihak bisnis). Nomor termasking.
 */
final class SendCustomerPhoneChangedNotification
{
    public function handle(CustomerPhoneChanged $event): void
    {
        $customer = Customer::query()->withTrashed()->find($event->customerId);
        if ($customer === null) {
            return;
        }

        $recipients = ApproverResolver::holders('customers.change_phone_approve')
            ->reject(fn (User $u) => $u->hasRole(RoleCatalog::SUPER_ADMIN))
            ->values();
        if ($recipients->isEmpty()) {
            return;
        }

        $approver = $event->approvedBy !== null ? User::query()->find($event->approvedBy, ['id', 'name']) : null;

        Notification::send($recipients, new CustomerPhoneChangedNotification(
            $customer->id,
            "{$customer->code} · {$customer->store_name}",
            PhoneNormalizer::mask($event->previousPhone),
            PhoneNormalizer::mask($event->newPhone),
            $approver?->name,
        ));
    }
}
