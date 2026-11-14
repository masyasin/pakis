<?php

namespace App\Support\Authorization;

use App\Domain\Access\DataScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Route-model binding ber-scope (06 §10, SEC-033): ID di luar scope data pengguna diperlakukan
 * seperti tidak ada → 404, bukan 403 (keberadaan data orang lain tidak bocor).
 *
 * Model yang memakai trait ini menyebut kolom pegawainya lewat `scopedEmployeeColumn()`
 * (mis. `id` untuk Employee, `employee_id` untuk AttendanceDay). Tanpa pengguna login → 404.
 *
 * @mixin Model
 */
trait ScopedRouteBinding
{
    /** Kolom (di tabel model ini) yang berisi ID pegawai pemilik data. */
    abstract public static function scopedEmployeeColumn(): string;

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $user = request()->user();
        if (! $user instanceof User) {
            return null;
        }

        $query = $this->resolveRouteBindingQuery($this->newQuery(), $value, $field);
        app(DataScope::class)->apply($query, $user, $this->qualifyColumn(static::scopedEmployeeColumn()));

        return $query->first();
    }
}
