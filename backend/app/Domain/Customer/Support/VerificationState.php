<?php

namespace App\Domain\Customer\Support;

use App\Domain\Customer\Actions\SubmitVerification;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerVerification;
use App\Domain\Customer\Otp\CustomerOtpService;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Carbon\Carbon;

/**
 * Props tab/halaman verifikasi kios (T-074; dipakai UI bagian 3). Satu tempat agar halaman detail &
 * halaman verifikasi menampilkan keadaan yang sama dengan yang diperiksa Action.
 */
final class VerificationState
{
    public function __construct(private readonly OfficeCallDrafts $drafts) {}

    /** @return array<string, mixed> */
    public function for(Customer $customer, User $user): array
    {
        $canManage = $user->can('submitVerification', $customer);
        $pending = SubmitVerification::pending($customer->id);
        $draft = $this->drafts->get($customer->id);
        $draftUser = $draft !== null ? User::query()->find($draft['submitted_by'], ['id', 'name']) : null;

        $history = CustomerVerification::query()->where('customer_id', $customer->id)
            ->with('submitter:id,name')->orderByDesc('id')->limit(20)->get();

        return [
            'otp_available' => CustomerOtpService::available(),
            'otp_length' => (int) setting('auth.otp_length'),
            'max_accuracy_m' => (int) setting('customer.verify_max_accuracy_m'),
            'can_submit' => $canManage && $customer->status === CustomerStatus::Prospect && $pending === null,
            'pending' => $pending !== null ? self::row($pending) : null,
            'office_call_draft' => $draft !== null ? [
                'submitted_by' => $draftUser !== null ? ['id' => $draftUser->id, 'name' => $draftUser->name] : null,
                'submitted_at' => DisplayTime::toLocal(Carbon::parse($draft['submitted_at']))->toIso8601String(),
                'requested_credit_limit' => $draft['requested_credit_limit'],
                // Pencatat telepon wajib bukan pengaju (BR-165b); server tetap memeriksa ulang.
                'can_record' => $canManage && $draft['submitted_by'] !== $user->id,
            ] : null,
            'history' => $history->map(fn (CustomerVerification $v) => self::row($v))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function row(CustomerVerification $v): array
    {
        return [
            'id' => $v->id,
            'type' => $v->type->value,
            'type_label' => $v->type->label(),
            'method' => $v->method->value,
            'method_label' => $v->method->label(),
            'status' => $v->status->value,
            'status_label' => $v->status->label(),
            'requested_credit_limit' => $v->requested_credit_limit,
            'office_call_note' => $v->office_call_note,
            'approval_request_id' => $v->approval_request_id,
            // Foto verifikasi di disk privat: tautan bertanda tangan 5 menit (SEC-108/109).
            'photo_url' => CustomerFileUrls::verificationPhoto($v),
            'submitted_by' => $v->submitter !== null ? ['id' => $v->submitter->id, 'name' => $v->submitter->name] : null,
            'created_at' => $v->created_at !== null ? DisplayTime::toLocal($v->created_at)->toIso8601String() : null,
        ];
    }
}
