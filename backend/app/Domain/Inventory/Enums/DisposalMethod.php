<?php

namespace App\Domain\Inventory\Enums;

/**
 * Metode berita acara (`disposal_reports.method`, BR-163). `destroy` & `handover_to_authority` =
 * mutasi `disposal` + `inventory.written_off`; `return_to_principal` = membuat retur ke prinsipal
 * (T-081) saat dilaksanakan, tanpa mutasi `disposal` (keputusan Mas, PA-69).
 */
enum DisposalMethod: string
{
    case Destroy = 'destroy';
    case ReturnToPrincipal = 'return_to_principal';
    case HandoverToAuthority = 'handover_to_authority';

    public function label(): string
    {
        return match ($this) {
            self::Destroy => 'Dimusnahkan',
            self::ReturnToPrincipal => 'Dikembalikan ke prinsipal',
            self::HandoverToAuthority => 'Diserahkan ke instansi berwenang',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
