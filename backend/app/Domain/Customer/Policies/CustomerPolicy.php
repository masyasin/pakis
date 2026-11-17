<?php

namespace App\Domain\Customer\Policies;

use App\Domain\Access\DataScope;
use App\Domain\Customer\Approvables\CustomerMerge;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Models\User;

/**
 * Kios (15 §5, §6.2): permission + dimensi Pelanggan. Route juga dijaga `can:` dan binding
 * ber-scope (404); Policy ini lapisan kedua.
 */
class CustomerPolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->viewAny($user) && $this->scope->allowsCustomer($user, $customer->id);
    }

    /** Scope `self` belum bisa membuat/mengalihkan kios dari web (PA-47): tidak ada area dalam scope. */
    public function create(User $user): bool
    {
        return $user->can('customers.manage') && $this->scope->assignableSalesAreaIds($user) !== [];
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customers.manage') && $this->scope->allowsCustomer($user, $customer->id);
    }

    public function reassign(User $user): bool
    {
        return $this->create($user);
    }

    /** Kirim verifikasi / OTP / catat telepon kantor (15 §5, T-074): pengelola kios dalam scope. */
    public function submitVerification(User $user, Customer $customer): bool
    {
        return $this->update($user, $customer);
    }

    /** Ganti HP pemilik (BR-126, BR-166(4)): hanya `customers.change_phone` (Admin Penjualan/Owner) dalam scope. */
    public function changePhone(User $user, Customer $customer): bool
    {
        return $user->can('customers.change_phone') && $this->scope->allowsCustomer($user, $customer->id)
            && $customer->status !== CustomerStatus::Merged;
    }

    /**
     * Ajukan limit kredit (BR-125): pengelola kios dalam scope, dan kios SUDAH terverifikasi. Action
     * menolak hal yang sama dengan `CUSTOMER_NOT_VERIFIED` (lapis kedua), CHECK DB lapis ketiga.
     */
    public function setCreditLimit(User $user, Customer $customer): bool
    {
        return $this->update($user, $customer) && $customer->verified_at !== null
            && in_array($customer->status, [CustomerStatus::Active, CustomerStatus::CreditHold], true);
    }

    /** Ajukan gabung (BR-166(5)): pengelola kios dalam scope; kios sumber belum nonaktif/digabung. */
    public function merge(User $user, Customer $customer): bool
    {
        return $this->update($user, $customer) && in_array($customer->status, CustomerMerge::SOURCE_STATUSES, true);
    }

    /** Tahan/lepas/nonaktif/aktifkan kembali (15 §6.6): pengelola kios dalam scope. */
    public function changeStatus(User $user, Customer $customer): bool
    {
        return $this->update($user, $customer) && $customer->status !== CustomerStatus::Merged;
    }

    /** Kelola kontak kios: pengelola kios dalam scope; kios digabung tidak (kontaknya sudah pindah). */
    public function manageContacts(User $user, Customer $customer): bool
    {
        return $this->changeStatus($user, $customer);
    }

    /** Antrean verifikasi (15 §5 `admin.customer-verifications.index`). */
    public function viewVerifications(User $user): bool
    {
        return $user->can('customers.verify');
    }

    /** HP penuh di daftar & detail hanya untuk pengelola (SEC-108); ekspor selalu termasking. */
    public function viewFullPhone(User $user): bool
    {
        return $user->can('customers.manage');
    }
}
