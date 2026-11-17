<?php

namespace App\Domain\Customer\Support;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Customer\Actions\RequestCreditLimit;
use App\Domain\Customer\Actions\RequestMerge;
use App\Domain\Customer\Actions\RequestPhoneChange;
use App\Domain\Customer\Actions\UpdateCustomer;
use App\Domain\Customer\Approvables\CustomerMerge;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Enums\StatusAction;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerContact;
use App\Domain\Customer\Otp\CustomerOtpService;
use App\Models\User;
use App\Support\Phone\PhoneNormalizer;
use App\Support\Time\DisplayTime;

/**
 * Props detail kios untuk aksi T-074 bagian 2 (dipakai UI bagian 3b): kontak, ganti HP, limit,
 * gabung, dan transisi status. Satu tempat agar tombol yang tampil sama dengan yang diperiksa
 * Policy/Action (server tetap memeriksa ulang). HP selalu termasking kecuali `customers.manage`.
 */
final class CustomerChangeState
{
    /** @return array<string, mixed> */
    public function for(Customer $customer, User $user): array
    {
        $fullPhone = $user->can('viewFullPhone', Customer::class);
        $phone = fn (?string $p) => $fullPhone ? $p : PhoneNormalizer::mask($p);
        $phonePending = RequestPhoneChange::pending($customer->id);
        $limitPending = RequestCreditLimit::pending($customer->id);
        $mergePending = RequestMerge::pending($customer->id);
        $canStatus = $user->can('changeStatus', $customer);

        $mergeTarget = $mergePending !== null ? Customer::query()->withTrashed()->find((int) ($mergePending->context[CustomerMerge::CONTEXT_TARGET] ?? 0), ['id', 'code', 'store_name']) : null;
        $mergedInto = $customer->merged_into_id !== null ? Customer::query()->withTrashed()->find($customer->merged_into_id, ['id', 'code', 'store_name']) : null;

        return [
            'contacts' => [
                'can_manage' => $user->can('manageContacts', $customer),
                'rows' => $customer->contacts()->orderBy('name')->get()->map(fn (CustomerContact $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'role' => $c->role,
                    'phone' => $phone($c->phone),
                ])->all(),
            ],
            'phone_change' => [
                // Prospek dengan HP belum terverifikasi: ubah lewat form, bukan jalur ganti HP.
                'can_request' => $user->can('changePhone', $customer) && ! UpdateCustomer::phoneEditable($customer) && $phonePending === null,
                'otp_available' => CustomerOtpService::available(),
                'otp_length' => (int) setting('auth.otp_length'),
                'pending' => $phonePending !== null ? [
                    'id' => $phonePending->id,
                    'approval_request_id' => $phonePending->approval_request_id,
                    'method' => $phonePending->method->value,
                    'method_label' => $phonePending->method->label(),
                    'phone_previous' => PhoneNormalizer::mask($phonePending->phone_previous),
                    'phone_target' => PhoneNormalizer::mask($phonePending->phone_target),
                    'submitted_by' => self::userRef($phonePending->submitted_by),
                    'created_at' => $phonePending->created_at !== null ? DisplayTime::toLocal($phonePending->created_at)->toIso8601String() : null,
                ] : null,
            ],
            'credit_limit' => [
                'can_request' => $user->can('setCreditLimit', $customer) && $limitPending === null,
                // Alasan bila tombol disembunyikan: kios belum terverifikasi (BR-125).
                'blocked_reason' => $customer->verified_at === null ? 'not_verified' : null,
                'pending' => self::approvalRef($limitPending),
            ],
            'merge' => [
                'can_request' => $user->can('merge', $customer) && $mergePending === null,
                'pending' => $mergePending !== null ? [
                    ...(array) self::approvalRef($mergePending),
                    'target' => $mergeTarget !== null ? ['id' => $mergeTarget->id, 'code' => $mergeTarget->code, 'store_name' => $mergeTarget->store_name] : null,
                ] : null,
                'merged_into' => $mergedInto !== null ? ['id' => $mergedInto->id, 'code' => $mergedInto->code, 'store_name' => $mergedInto->store_name] : null,
            ],
            'status' => [
                'hold_reason' => in_array($customer->status, [CustomerStatus::CreditHold, CustomerStatus::Inactive], true) ? $customer->hold_reason : null,
                'actions' => $canStatus ? array_map(fn (StatusAction $a) => ['value' => $a->value, 'label' => $a->label()], StatusAction::availableFor($customer->status)) : [],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private static function approvalRef(?ApprovalRequest $request): ?array
    {
        if ($request === null) {
            return null;
        }

        return [
            'approval_request_id' => $request->id,
            'amount' => $request->amount,
            'requested_by' => self::userRef($request->requester_user_id),
            'created_at' => $request->created_at !== null ? DisplayTime::toLocal($request->created_at)->toIso8601String() : null,
        ];
    }

    /** @return array{id: int, name: string}|null */
    private static function userRef(?int $id): ?array
    {
        $user = $id !== null ? User::query()->find($id, ['id', 'name']) : null;

        return $user !== null ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}
