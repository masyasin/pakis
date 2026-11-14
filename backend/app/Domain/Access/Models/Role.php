<?php

namespace App\Domain\Access\Models;

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Support\RoleCatalog;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Role (Spatie) + kolom PAKIS (04 §2.3, ADR-011). `name` adalah KODE role (dipakai `hasRole`),
 * `label` nama yang tampil di layar.
 *
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property string $label
 * @property string|null $description
 * @property DataScope $data_scope
 * @property bool $mfa_required
 * @property bool $is_system
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Role extends SpatieRole
{
    protected $attributes = [
        'data_scope' => 'self',
        'mfa_required' => false,
        'is_system' => false,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data_scope' => DataScope::class,
            'mfa_required' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Permission, scope, dan MFA `owner`/`super_admin` tidak bisa diubah siapa pun (BR-031). */
    public function isLocked(): bool
    {
        return in_array($this->name, RoleCatalog::LOCKED, true);
    }
}
