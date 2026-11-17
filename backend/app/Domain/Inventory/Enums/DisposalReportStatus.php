<?php

namespace App\Domain\Inventory\Enums;

/** Status berita acara pemusnahan (`disposal_reports.status`, 15 §6.6, BR-163). Nilai identik dengan ENUM DB. */
enum DisposalReportStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Executed = 'executed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::PendingApproval => 'Menunggu persetujuan',
            self::Approved => 'Disetujui',
            self::Executed => 'Dilaksanakan',
            self::Rejected => 'Ditolak',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
