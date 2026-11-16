<?php

namespace App\Domain\Approval\Enums;

/** Jenis penyetuju satu langkah (15 §4.2 `approver_type`, BR-153(3)). */
enum ApproverType: string
{
    case Permission = 'permission';
    case DirectSupervisor = 'direct_supervisor';
    case LocationHead = 'location_head';

    public function label(): string
    {
        return match ($this) {
            self::Permission => 'Pemegang izin',
            self::DirectSupervisor => 'Atasan langsung',
            self::LocationHead => 'Kepala lokasi',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
