<?php

namespace App\Domain\Customer\Approvables;

use App\Domain\Approval\Contracts\Approvable;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Support\Rupiah;
use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Support\CustomerApprovalScope;
use App\Domain\Settings\DecimalString;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use LogicException;

/**
 * Dokumen approval `customer_credit_limit` (15 §4.2.1, BR-125, SEC-110) = baris `customers` itu
 * sendiri (PA-49: tanpa tabel dokumen baru), `document_id` = id kios. Limit yang diminta disimpan
 * sebagai `approval_requests.amount` (nominal aturan) dan dibaca kembali saat disetujui; satu kios
 * hanya punya satu permintaan limit yang menunggu (`apr_one_pending_unique`).
 *
 * Rantai bawaan (keputusan Mas 16 Nov 2026, PA-51): < Rp 20 jt `credit_limits.set`; ≥ Rp 20 jt
 * `direct_supervisor` sales kios lalu `credit_limits.set_high` — dua izin/orang berbeda sehingga
 * BR-153(11) tidak membuat langkah 2 buntu di Owner.
 */
class CustomerCreditLimit extends Customer implements Approvable
{
    public const DOCUMENT_TYPE = 'customer_credit_limit';

    protected $table = 'customers';

    /** Limit yang sedang diajukan (hanya saat pengajuan; setelahnya dibaca dari `amount`). */
    public ?string $requestedLimit = null;

    public ?string $changeReason = null;

    /** Audit & relasi polimorfik tetap atas nama kios, bukan subkelas dokumen. */
    public function getMorphClass(): string
    {
        return Customer::class;
    }

    public function approvalDocumentType(): string
    {
        return self::DOCUMENT_TYPE;
    }

    public function approvalAmount(): ?string
    {
        return DecimalString::normalize($this->requestedLimit ?? throw new LogicException('Limit yang diajukan belum diisi.'), 2);
    }

    public function approvalSummary(): string
    {
        return "{$this->code} · {$this->store_name} · Limit ".Rupiah::format((string) $this->approvalAmount());
    }

    public function approvalContext(): array
    {
        $this->loadMissing(['salesArea:id,name', 'assignedSales:id,full_name']);

        return array_filter([
            'Kios' => "{$this->code} · {$this->store_name}",
            'Pemilik' => $this->owner_name,
            'Area' => $this->salesArea?->name,
            'Sales' => $this->assignedSales?->full_name,
            'Status' => $this->status->label(),
            'Limit sekarang' => Rupiah::format($this->credit_limit).($this->credit_limit_active ? '' : ' (tidak aktif)'),
            'Limit diminta' => Rupiah::format((string) $this->approvalAmount()),
            'Alasan' => $this->changeReason,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function approvalConditions(): array
    {
        return [];
    }

    public function approvalScopeLocationId(): ?int
    {
        return CustomerApprovalScope::locationId($this);
    }

    public function approvalScopeEmployeeId(): ?int
    {
        return CustomerApprovalScope::employeeId($this);
    }

    /** Pembuat "dokumen" = pengaju (maker-checker mesin); kios-nya sendiri tidak punya pembuat dokumen. */
    public function creatorUserId(): ?int
    {
        return null;
    }

    public function approvalUrl(): ?string
    {
        return '/pelanggan/'.$this->id.'?tab=limit';
    }

    /**
     * Limit hanya untuk kios terverifikasi yang masih aktif/tahan kredit (BR-125, CHECK
     * `customers_active_limit_chk` tetap lapis kedua). Limit 0 = tanpa limit (nonaktif).
     */
    public function onApproved(?ApprovalRequest $request): void
    {
        $limit = $request->amount ?? $this->requestedLimit ?? throw new LogicException('Limit yang disetujui tidak diketahui.');
        $limit = DecimalString::normalize($limit, 2);
        $customer = Customer::query()->lockForUpdate()->findOrFail($this->id);
        self::assertEligible($customer);

        $before = ['credit_limit' => $customer->credit_limit, 'credit_limit_active' => $customer->credit_limit_active];
        $customer->forceFill([
            'credit_limit' => $limit,
            'credit_limit_active' => DecimalString::compare($limit, '0') > 0,
        ])->save();

        app(AuditLogger::class)->record('customer.credit_limit_changed', $customer, $before, [
            'credit_limit' => $customer->credit_limit,
            'credit_limit_active' => $customer->credit_limit_active,
            'approval_request_id' => $request?->id,
        ]);
    }

    public function onRejected(ApprovalRequest $request): void {}

    public function onCancelled(ApprovalRequest $request): void {}

    /** BR-125: kios belum terverifikasi → `CUSTOMER_NOT_VERIFIED`; digabung/nonaktif/prospek → 409. */
    public static function assertEligible(Customer $customer): void
    {
        if ($customer->verified_at === null) {
            throw new ApiError(ErrorCode::CUSTOMER_NOT_VERIFIED, ['credit_limit' => ErrorCode::CUSTOMER_NOT_VERIFIED->message()]);
        }
        if (! in_array($customer->status, [CustomerStatus::Active, CustomerStatus::CreditHold], true)) {
            throw new ApiError(ErrorCode::STATE_CONFLICT,
                publicMessage: 'Limit kredit hanya bisa diubah untuk kios berstatus Aktif atau Tahan kredit.');
        }
    }
}
