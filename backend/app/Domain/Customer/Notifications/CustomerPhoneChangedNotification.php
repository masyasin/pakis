<?php

namespace App\Domain\Customer\Notifications;

use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;

/**
 * HP kios diganti (15 §9.1 `customer_phone_changed`, BR-166(4), TA-04). Teks yang sama dikirim ke
 * kanal luar dan disimpan di `notifications`, jadi HP lama & baru SELALU termasking di sini.
 */
class CustomerPhoneChangedNotification extends PakisNotification
{
    public function __construct(
        private readonly int $customerId,
        private readonly string $customerLabel,
        private readonly string $previousMasked,
        private readonly string $newMasked,
        private readonly ?string $approverName,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::CustomerPhoneChanged;
    }

    public function title(): string
    {
        return "HP kios diganti: {$this->customerLabel}";
    }

    public function body(): string
    {
        $by = $this->approverName !== null ? " Disetujui {$this->approverName}." : '';

        return "Nomor pemilik {$this->previousMasked} diganti menjadi {$this->newMasked}.{$by} Bila Anda tidak mengenali perubahan ini, periksa riwayat kios.";
    }

    public function url(): ?string
    {
        return '/pelanggan/'.$this->customerId.'?tab=riwayat';
    }
}
