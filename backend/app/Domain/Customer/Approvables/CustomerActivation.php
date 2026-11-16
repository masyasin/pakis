<?php

namespace App\Domain\Customer\Approvables;

use App\Domain\Approval\Contracts\Approvable;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Models\ApprovalStep;
use App\Domain\Approval\Support\Rupiah;
use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Enums\VerificationMethod;
use App\Domain\Customer\Enums\VerificationStatus;
use App\Domain\Customer\Enums\VerificationType;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerVerification;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Settings\DecimalString;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Phone\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Dokumen approval `customer_activation` (15 §4.2.1, BR-165, T-074) = baris `customer_verifications`
 * ber-`type = activation` (PA-49: tanpa tabel dokumen baru). Nominal = limit kredit yang diminta,
 * sehingga satu aturan bertingkat memilih rantainya (< Rp 20 jt `customers.verify`; ≥ Rp 20 jt
 * + `credit_limits.set_high`, PA-51).
 *
 * Maker-checker (SEC-101, BR-165b): pengaju = `submitted_by` (sales). Pencatat telepon kantor bukan
 * pengaju, jadi boleh memutuskan bila memegang izin langkahnya (keputusan Mas 16 Nov 2026).
 *
 * Scope penyetuju (PA-53): pegawai = sales kios, lokasi = lokasi induk gudang asal kios (gudang kios
 * atau gudang area).
 */
class CustomerActivation extends CustomerVerification implements Approvable
{
    public const DOCUMENT_TYPE = 'customer_activation';

    /** Nama pencatat telepon kantor untuk konteks penyetuju (tidak ada kolomnya, PA-49). */
    public ?string $officeCallerName = null;

    protected static function booted(): void
    {
        static::addGlobalScope('activation', fn (Builder $q) => $q->where('customer_verifications.type', VerificationType::Activation->value));
        static::creating(function (self $v) {
            $v->type = VerificationType::Activation;
        });
    }

    public function approvalDocumentType(): string
    {
        return self::DOCUMENT_TYPE;
    }

    /** Selalu ada nominal (0 bila tanpa limit) agar aturan bertingkat berlaku (RuleMatcher). */
    public function approvalAmount(): ?string
    {
        return DecimalString::normalize($this->requested_credit_limit ?? '0', 2);
    }

    public function approvalSummary(): string
    {
        $customer = $this->customerOrFail();

        return "{$customer->code} · {$customer->store_name} · Limit ".Rupiah::format((string) $this->approvalAmount());
    }

    public function approvalContext(): array
    {
        $customer = $this->customerOrFail();
        $customer->loadMissing(['salesArea:id,name', 'assignedSales:id,full_name']);

        // Ditampilkan apa adanya ke penyetuju: HP selalu termasking, tanpa koordinat.
        return array_filter([
            'Kios' => "{$customer->code} · {$customer->store_name}",
            'Pemilik' => $customer->owner_name,
            'HP pemilik' => PhoneNormalizer::mask($this->phone_target),
            'Area' => $customer->salesArea?->name,
            'Sales' => $customer->assignedSales?->full_name,
            'Bukti nomor' => $this->method->label(),
            'Catatan telepon kantor' => $this->office_call_note,
            'Ditelepon oleh' => $this->officeCallerName,
            'Limit diminta' => Rupiah::format((string) $this->approvalAmount()),
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function approvalConditions(): array
    {
        return [];
    }

    public function approvalScopeLocationId(): ?int
    {
        $customer = $this->customerOrFail();
        $stockLocationId = $customer->home_stock_location_id ?? $customer->salesArea?->home_stock_location_id;

        return $stockLocationId === null ? null : StockLocation::query()->withTrashed()->whereKey($stockLocationId)->value('location_id');
    }

    public function approvalScopeEmployeeId(): ?int
    {
        return $this->customerOrFail()->assigned_sales_employee_id;
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
     * Disetujui (BR-165): foto, titik, limit disalin ke kios; `verified_at`/`verified_by`, HP
     * terverifikasi, status `active`. Kios dikunci dulu; bila kios sudah bukan prospek atau HP-nya
     * berubah sejak pengajuan, keputusan ditolak (409) agar data lama tidak menimpa yang baru.
     */
    public function onApproved(?ApprovalRequest $request): void
    {
        $customer = Customer::query()->lockForUpdate()->findOrFail($this->customer_id);
        if ($customer->status !== CustomerStatus::Prospect || $customer->owner_phone !== $this->phone_target) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $customer->status->value],
                'Data kios sudah berubah sejak verifikasi diajukan. Tolak permintaan ini lalu minta sales mengajukan ulang.');
        }

        $limit = DecimalString::normalize($this->requested_credit_limit ?? '0', 2);
        $before = [
            'status' => $customer->status->value,
            'credit_limit' => $customer->credit_limit,
            'credit_limit_active' => $customer->credit_limit_active,
            'verified_at' => null,
        ];

        $customer->forceFill([
            'status' => CustomerStatus::Active,
            'photo_path' => $this->photo_path ?? $customer->photo_path,
            'latitude' => $this->latitude ?? $customer->latitude,
            'longitude' => $this->longitude ?? $customer->longitude,
            'credit_limit' => $limit,
            'credit_limit_active' => DecimalString::compare($limit, '0') > 0,
            'owner_phone_verified_at' => $this->otp_verified_at ?? now(),
            'verified_at' => now(),
            'verified_by' => self::finalDecider($request),
        ])->save();

        $this->forceFill(['status' => VerificationStatus::Approved])->save();

        app(AuditLogger::class)->record('customer.activated', $customer, $before, [
            'status' => CustomerStatus::Active->value,
            'credit_limit' => $customer->credit_limit,
            'credit_limit_active' => $customer->credit_limit_active,
            'verified_at' => $customer->verified_at?->toIso8601String(),
            'verification_id' => $this->id,
            'method' => $this->method->value,
        ]);
    }

    public function onRejected(ApprovalRequest $request): void
    {
        $this->forceFill(['status' => VerificationStatus::Rejected])->save();
    }

    public function onCancelled(ApprovalRequest $request): void
    {
        $this->forceFill(['status' => VerificationStatus::Cancelled])->save();
    }

    public function isOfficeCall(): bool
    {
        return $this->method === VerificationMethod::OfficeCall;
    }

    private function customerOrFail(): Customer
    {
        return $this->customer ?? throw new LogicException("Kios verifikasi #{$this->id} tidak ditemukan.");
    }

    /** Pemutus langkah terakhir (penyetuju akhir) = `verified_by`; null bila disetujui otomatis. */
    private static function finalDecider(?ApprovalRequest $request): ?int
    {
        if ($request === null) {
            return null;
        }
        $last = $request->steps->filter(fn (ApprovalStep $s) => $s->decided_by !== null)->sortByDesc('step_order')->first();

        return $last?->decided_by;
    }
}
