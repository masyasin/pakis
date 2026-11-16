<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Purchasing\Models\Supplier;
use App\Models\User;

/**
 * Buka nomor rekening supplier lengkap (SEC-108, `suppliers.manage`). Satu-satunya jalan nomor
 * plaintext keluar dari server; setiap pembukaan tercatat `sensitive_data_viewed` dan audit
 * `supplier.account_viewed` — keduanya tanpa nomor rekening. Pola sama dengan RevealNik.
 */
final class RevealSupplierAccount
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecurityLogger $security,
    ) {}

    /** @return string|null nomor lengkap; null bila supplier belum punya rekening (tidak dicatat) */
    public function handle(User $actor, Supplier $supplier): ?string
    {
        if (! $supplier->hasBankAccount()) {
            return null;
        }

        $this->security->record(SecurityEventType::SensitiveDataViewed, SecurityChannel::Web, $actor, context: [
            'field' => 'bank_account',
            'supplier_id' => $supplier->id,
        ]);
        $this->audit->record('supplier.account_viewed', $supplier);

        return (string) $supplier->bank_account_no_enc;
    }
}
