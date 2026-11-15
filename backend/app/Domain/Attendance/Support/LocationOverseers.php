<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Organization\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Penerima notifikasi "HRD + kepala lokasi pegawai" (BR-070): lokasi palsu (T-036) dan koreksi
 * tanpa atasan aktif (T-040). Hanya akun aktif, tanpa pelaku sendiri.
 */
final class LocationOverseers
{
    /** @return Collection<int, User> */
    public static function for(?Location $location, ?int $exceptUserId = null): Collection
    {
        $headUserId = $location?->head_employee_id === null
            ? null
            : User::query()->where('employee_id', $location->head_employee_id)->where('is_active', true)->value('id');

        // (HRD ∪ kepala lokasi) ∩ aktif ∩ bukan pelaku — OR dibungkus agar syarat lain berlaku ke keduanya.
        return User::query()
            ->where('is_active', true)
            ->when($exceptUserId !== null, fn (Builder $q) => $q->whereKeyNot($exceptUserId))
            ->where(function (Builder $q) use ($headUserId) {
                $q->role('hrd');
                if ($headUserId !== null) {
                    $q->orWhere('users.id', $headUserId);
                }
            })
            ->get();
    }
}
