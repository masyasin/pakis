<?php

namespace App\Domain\Device\Listings;

use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Support\LocationScope;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Time\DisplayTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar perangkat Pakis Pro (`admin.devices.index`, katalog 07 §3.4: `devices`). Scope data lewat
 * pegawai pemilik akun (06 §5) — diterapkan controller/exportQuery dengan `Device::visibleTo`.
 * Default status pending + active: yang perlu ditindak & yang sedang dipakai.
 */
final class DeviceListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('devices')
            ->searchPlaceholder('Cari nama, username, atau model HP')
            ->search(TextSearch::make()
                ->relation('user', 'name', prefix: false)
                ->relation('user', 'username')
                ->contains('model'))
            ->filters([
                MultiSelectFilter::make('status', 'Status')
                    ->options(DeviceStatus::options())
                    ->default([DeviceStatus::Pending->value, DeviceStatus::Active->value]),
                RelationFilter::make('location', 'Lokasi')
                    ->source('locations')
                    ->validIds(fn () => self::visibleLocations())
                    ->using(fn (Builder $q, int $id) => $q->whereHas('user.employee.primaryAssignment', fn (Builder $a) => $a->where('location_id', $id))),
                SelectFilter::make('app_version', 'Versi app')
                    ->options(self::versionOptions()),
                DateRangeFilter::make('last_seen_at', 'Terakhir aktif')->onTimestamp(),
            ])
            ->sorts([
                Sort::make('created_at', 'Didaftarkan'),
                Sort::make('last_seen_at', 'Terakhir aktif'),
            ])
            ->defaultSort('-created_at')
            ->exportable();
    }

    public function exportQuery(User $user): Builder
    {
        return Device::query()->visibleTo($user)->with(['user:id,name,username,employee_id', 'user.employee:id,employee_number,full_name']);
    }

    /**
     * @return ExportColumns<Device>
     */
    public function exportColumns(): ExportColumns
    {
        $time = fn ($t) => $t === null ? null : DisplayTime::toLocal($t)->format('Y-m-d H:i');

        return ExportColumns::for(Device::class)
            ->add('Pengguna', fn (Device $d) => $d->user->name)
            ->add('Username', fn (Device $d) => $d->user->username)
            ->add('No. pegawai', fn (Device $d) => $d->user->employee?->employee_number)
            ->add('Model', fn (Device $d) => $d->model)
            ->add('OS', fn (Device $d) => $d->os_version)
            ->add('Versi app', fn (Device $d) => $d->app_version)
            ->add('Status', fn (Device $d) => $d->status->label())
            ->add('Alasan', fn (Device $d) => $d->request_reason)
            ->add('Didaftarkan', fn (Device $d) => $time($d->created_at))
            ->add('Terakhir aktif', fn (Device $d) => $time($d->last_seen_at));
    }

    /**
     * Versi yang benar-benar ada di data (terbaru dulu) — opsi tetap kecil karena versi app sedikit.
     *
     * @return array<string, string>
     */
    private static function versionOptions(): array
    {
        $versions = Device::query()->whereNotNull('app_version')->distinct()->pluck('app_version')
            ->map(fn ($v): string => (string) $v)
            ->sortDesc(SORT_NATURAL)
            ->values()
            ->all();

        return array_combine($versions, $versions);
    }

    /** @return Builder<Location> */
    private static function visibleLocations(): Builder
    {
        $user = request()->user();

        return $user instanceof User
            ? app(LocationScope::class)->apply(Location::query(), $user)
            : Location::query()->whereRaw('1 = 0');
    }
}
