<?php

namespace App\Domain\Catalog\Notifications;

use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;

/**
 * Izin edar mendekati habis (15 §9.1 `registration_expiring`, BR-152) → pemegang
 * `product_registrations.manage`. Satu notifikasi per izin per ambang (H-60/30/7/0).
 */
class RegistrationExpiringNotification extends PakisNotification
{
    public function __construct(
        private readonly int $productId,
        private readonly string $productName,
        private readonly string $registrationNo,
        private readonly string $validUntilLabel,
        private readonly int $daysLeft,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::RegistrationExpiring;
    }

    public function title(): string
    {
        return $this->daysLeft === 0
            ? "Izin edar habis hari ini: {$this->productName}"
            : "Izin edar habis {$this->daysLeft} hari lagi: {$this->productName}";
    }

    public function body(): string
    {
        return "Izin {$this->registrationNo} berlaku sampai {$this->validUntilLabel}. Tambahkan izin perpanjangan agar produk tetap bisa dijual.";
    }

    public function url(): ?string
    {
        return route('admin.products.show', ['product' => $this->productId, 'tab' => 'izin'], false);
    }
}
