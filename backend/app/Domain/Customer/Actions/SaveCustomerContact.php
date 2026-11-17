<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerContact;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Tambah / ubah / hapus kontak kios (15 §5 `admin.customers.contacts.*`, T-074), `customers.manage`
 * dalam scope. Diaudit pada kios (`customer.contact_added|contact_updated|contact_removed`) agar
 * riwayat kios lengkap. Kios yang sudah digabung tidak dikelola lagi (kontaknya sudah pindah).
 */
final class SaveCustomerContact
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array{name: string, role: string|null, phone: string|null}  $data */
    public function handle(Customer $customer, ?CustomerContact $contact, array $data, ?int $userId): CustomerContact
    {
        return DB::transaction(function () use ($customer, $contact, $data, $userId) {
            self::assertEditable($customer);
            $before = $contact?->audited();
            $contact ??= new CustomerContact(['customer_id' => $customer->id, 'created_by' => $userId]);
            $contact->fill($data);
            if (! $contact->exists || $contact->isDirty()) {
                $contact->updated_by = $userId;
                $created = ! $contact->exists;
                $contact->save();
                $this->audit->record($created ? 'customer.contact_added' : 'customer.contact_updated', $customer, $before,
                    ['contact_id' => $contact->id, ...$contact->audited()]);
            }

            return $contact;
        });
    }

    public function remove(Customer $customer, CustomerContact $contact): void
    {
        DB::transaction(function () use ($customer, $contact) {
            self::assertEditable($customer);
            $this->audit->record('customer.contact_removed', $customer, ['contact_id' => $contact->id, ...$contact->audited()], null);
            $contact->delete();
        });
    }

    private static function assertEditable(Customer $customer): void
    {
        if ($customer->status === CustomerStatus::Merged) {
            throw new ApiError(ErrorCode::STATE_CONFLICT,
                publicMessage: 'Kios ini sudah digabung; kelola kontak di kios tujuannya.');
        }
    }
}
