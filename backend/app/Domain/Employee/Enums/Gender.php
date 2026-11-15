<?php

namespace App\Domain\Employee\Enums;

/** Jenis kelamin pegawai; nilai identik dengan ENUM `employees.gender` (04 §2.9). */
enum Gender: string
{
    case Male = 'L';
    case Female = 'P';

    public function label(): string
    {
        return match ($this) {
            self::Male => 'Laki-laki',
            self::Female => 'Perempuan',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $g) => ['value' => $g->value, 'label' => $g->label()], self::cases());
    }
}
