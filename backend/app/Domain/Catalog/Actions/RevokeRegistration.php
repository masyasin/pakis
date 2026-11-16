<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Enums\RegistrationStatus;
use App\Domain\Catalog\Models\ProductRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cabut izin edar (BR-152): hanya manual dan wajib alasan. Izin yang sudah habis tidak perlu dicabut
 * (statusnya sudah tidak berlaku); izin yang sudah dicabut tidak bisa dicabut lagi.
 */
final class RevokeRegistration
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(ProductRegistration $registration, string $reason, ?int $userId): ProductRegistration
    {
        return DB::transaction(function () use ($registration, $reason, $userId) {
            $locked = ProductRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            if ($locked->status !== RegistrationStatus::Active) {
                throw ValidationException::withMessages(['reason' => 'Hanya izin yang masih berlaku yang bisa dicabut.']);
            }

            $before = $locked->audited();
            $locked->forceFill(['status' => RegistrationStatus::Revoked, 'note' => trim($reason), 'updated_by' => $userId])->save();
            $this->audit->record('product_registration.revoked', $locked, $before, $locked->audited());

            return $locked;
        });
    }
}
