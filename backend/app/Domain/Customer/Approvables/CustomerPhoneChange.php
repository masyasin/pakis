<?php

namespace App\Domain\Customer\Approvables;

use App\Domain\Approval\Contracts\Approvable;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Enums\VerificationStatus;
use App\Domain\Customer\Enums\VerificationType;
use App\Domain\Customer\Events\CustomerPhoneChanged;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerVerification;
use App\Domain\Customer\Support\CustomerApprovalScope;
use App\Domain\Customer\Support\PhoneConflicts;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Phone\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Dokumen approval `customer_phone_change` (15 §4.2.1, BR-126, BR-166(4), SEC-105, TA-04) = baris
 * `customer_verifications` ber-`type = phone_change` (PA-49): `phone_previous` = HP lama yang
 * dibuktikan (OTP WhatsApp ke HP LAMA atau telepon kantor), `phone_target` = HP baru.
 *
 * Disetujui (rantai bawaan `customers.change_phone_approve` = Owner): HP baru diterapkan setelah
 * dicek ulang BR-166(3) (bisa saja nomor itu sejak pengajuan dipakai kios/pegawai lain), audit nilai
 * lama/baru, lalu event CustomerPhoneChanged → notifikasi termasking ke pemegang izin persetujuan.
 */
class CustomerPhoneChange extends CustomerVerification implements Approvable
{
    public const DOCUMENT_TYPE = 'customer_phone_change';

    /** Alasan ganti dari pengaju, hanya untuk konteks penyetuju & audit (tidak ada kolomnya). */
    public ?string $changeReason = null;

    protected static function booted(): void
    {
        static::addGlobalScope('phone_change', fn (Builder $q) => $q->where('customer_verifications.type', VerificationType::PhoneChange->value));
        static::creating(function (self $v) {
            $v->type = VerificationType::PhoneChange;
        });
    }

    public function approvalDocumentType(): string
    {
        return self::DOCUMENT_TYPE;
    }

    public function approvalAmount(): ?string
    {
        return null;
    }

    public function approvalSummary(): string
    {
        $customer = $this->customerOrFail();

        return "{$customer->code} · {$customer->store_name} · Ganti HP ".PhoneNormalizer::mask((string) $this->phone_previous).' → '.PhoneNormalizer::mask($this->phone_target);
    }

    public function approvalContext(): array
    {
        $customer = $this->customerOrFail();
        $customer->loadMissing(['salesArea:id,name', 'assignedSales:id,full_name']);

        // Ditampilkan apa adanya ke penyetuju: kedua HP termasking (SEC-108).
        return array_filter([
            'Kios' => "{$customer->code} · {$customer->store_name}",
            'Pemilik' => $customer->owner_name,
            'HP lama' => PhoneNormalizer::mask((string) $this->phone_previous),
            'HP baru' => PhoneNormalizer::mask($this->phone_target),
            'Area' => $customer->salesArea?->name,
            'Sales' => $customer->assignedSales?->full_name,
            'Bukti nomor lama' => $this->method->label(),
            'Catatan telepon kantor' => $this->office_call_note,
            'Alasan' => $this->changeReason,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function approvalConditions(): array
    {
        return [];
    }

    public function approvalScopeLocationId(): ?int
    {
        return CustomerApprovalScope::locationId($this->customerOrFail());
    }

    public function approvalScopeEmployeeId(): ?int
    {
        return CustomerApprovalScope::employeeId($this->customerOrFail());
    }

    public function creatorUserId(): ?int
    {
        return $this->submitted_by;
    }

    public function approvalUrl(): ?string
    {
        return '/pelanggan/'.$this->customer_id.'?tab=verifikasi';
    }

    /**
     * Kios dikunci; bila HP kios sudah bukan HP lama yang dibuktikan, kios sudah digabung, atau HP
     * baru kini bentrok (kios lain/pegawai/akun), keputusan ditolak agar data tidak tertimpa.
     */
    public function onApproved(?ApprovalRequest $request): void
    {
        $customer = Customer::query()->lockForUpdate()->findOrFail($this->customer_id);
        if ($customer->status === CustomerStatus::Merged || $customer->owner_phone !== $this->phone_previous) {
            throw new ApiError(ErrorCode::STATE_CONFLICT,
                publicMessage: 'HP atau status kios sudah berubah sejak ganti HP diajukan. Tolak permintaan ini lalu ajukan ulang.');
        }
        app(PhoneConflicts::class)->assertUsable($this->phone_target, $customer->id, 'new_phone');

        $before = ['owner_phone' => $customer->owner_phone, 'owner_phone_verified_at' => $customer->owner_phone_verified_at?->toIso8601String()];
        $approvedBy = CustomerApprovalScope::finalDecider($request);

        // HP baru dianggap terverifikasi: buktinya = verifikasi HP lama + persetujuan Owner (BR-166(4)),
        // sehingga form ubah kios tetap tidak bisa mengganti nomor ini tanpa jalur ganti HP.
        $customer->forceFill([
            'owner_phone' => $this->phone_target,
            'owner_phone_verified_at' => now(),
        ])->save();
        $this->forceFill(['status' => VerificationStatus::Approved])->save();

        // HP kios adalah data kios (09 §7.1): nilai lama/baru disimpan utuh untuk penelusuran TA-04.
        app(AuditLogger::class)->record('customer.phone_changed', $customer, $before, [
            'owner_phone' => $customer->owner_phone,
            'owner_phone_verified_at' => $customer->owner_phone_verified_at?->toIso8601String(),
            'verification_id' => $this->id,
            'method' => $this->method->value,
        ]);

        event(new CustomerPhoneChanged($customer->id, (string) $this->phone_previous, $this->phone_target, $approvedBy));
    }

    public function onRejected(ApprovalRequest $request): void
    {
        $this->forceFill(['status' => VerificationStatus::Rejected])->save();
    }

    public function onCancelled(ApprovalRequest $request): void
    {
        $this->forceFill(['status' => VerificationStatus::Cancelled])->save();
    }

    private function customerOrFail(): Customer
    {
        return $this->customer ?? throw new LogicException("Kios ganti HP #{$this->id} tidak ditemukan.");
    }
}
