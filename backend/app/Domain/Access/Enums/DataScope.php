<?php

namespace App\Domain\Access\Enums;

/** Cakupan data role (06 §5). Nilai identik dengan ENUM `roles.data_scope`. */
enum DataScope: string
{
    case All = 'all';
    case Location = 'location';
    case Team = 'team';
    case Self = 'self';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Semua',
            self::Location => 'Lokasi',
            self::Team => 'Tim',
            self::Self => 'Diri sendiri',
        };
    }

    /** Penjelasan untuk pilihan di form role (06 §5). */
    public function help(): string
    {
        return match ($this) {
            self::All => 'Semua pegawai di semua lokasi.',
            self::Location => 'Pegawai di lokasi tempat pengguna bertugas atau yang ia kepalai.',
            self::Team => 'Diri sendiri dan bawahan langsung maupun tidak langsung.',
            self::Self => 'Hanya data milik sendiri.',
        };
    }
}
