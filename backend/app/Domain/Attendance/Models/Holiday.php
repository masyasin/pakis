<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Organization\Models\Location;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Hari libur (04 §2.13, FR-033, BR-062) dengan rentang tanggal `start_date`–`end_date` (inklusif;
 * libur satu hari = keduanya sama). Tanggal lokal (`general.timezone`), sama seperti `work_date`;
 * jangan dikonversi zona waktu.
 *
 * @property int $id
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string $name
 * @property string|null $description
 * @property bool $is_national
 * @property bool $applies_to_all
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Location> $locations
 */
class Holiday extends Model
{
    /** Kolom yang diaudit & dibandingkan saat ubah. `location_ids` ditambahkan Action (pivot). */
    public const AUDITED = ['start_date', 'end_date', 'name', 'description', 'is_national', 'applies_to_all'];

    protected $fillable = [...self::AUDITED, 'created_by', 'updated_by'];

    protected $attributes = [
        'is_national' => true,
        'applies_to_all' => true,
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'is_national' => 'boolean',
            'applies_to_all' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Location, $this> */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'holiday_locations');
    }

    /**
     * Libur yang berlaku pada tanggal itu untuk pegawai di lokasi tertentu (BR-062): tanggal berada
     * di rentang libur, dan libur berlaku semua lokasi atau lokasinya ada di `holiday_locations`. `$locationId` null (pegawai tanpa penugasan
     * utama) hanya terkena libur yang berlaku semua. Dipakai ScheduleResolver (T-035).
     *
     * @param  Builder<Holiday>  $query
     */
    public function scopeAppliesTo(Builder $query, CarbonInterface|string $date, ?int $locationId): void
    {
        // Perbandingan langsung (bukan whereDate/DATE()) agar index `holidays_range_idx` terpakai.
        $day = $date instanceof CarbonInterface ? $date->toDateString() : $date;
        $query->where($query->qualifyColumn('start_date'), '<=', $day)
            ->where($query->qualifyColumn('end_date'), '>=', $day);
        self::constrainToLocation($query, $locationId);
    }

    /** Tanggal (`Y-m-d` lokal) berada di rentang libur (inklusif). Kembaran dalam memori scopeAppliesTo. */
    public function covers(string $date): bool
    {
        return $this->start_date->toDateString() <= $date && $this->end_date->toDateString() >= $date;
    }

    /**
     * Kembaran dalam memori constrainToLocation, untuk ScheduleResolver yang menilai banyak
     * (pegawai, tanggal) sekaligus tanpa query per sel. Butuh relasi `locations` sudah dimuat.
     * Kesetaraannya dengan scope query dijaga test (ScheduleResolverTest).
     */
    public function appliesToLocation(?int $locationId): bool
    {
        return $this->applies_to_all
            || ($locationId !== null && $this->locations->contains(fn (Location $l) => $l->id === $locationId));
    }

    /** Jumlah hari kalender libur (inklusif), mis. 30 Mar–1 Apr = 3. */
    public function dayCount(): int
    {
        return (int) $this->start_date->diffInDays($this->end_date) + 1;
    }

    /**
     * Nilai untuk audit & perbandingan: tanggal sebagai `Y-m-d` (bukan objek Carbon) dan daftar
     * lokasi terurut, agar urutan pilihan di form tidak dianggap perubahan.
     *
     * @param  list<int>  $locationIds
     * @return array<string, mixed>
     */
    public function auditValues(array $locationIds): array
    {
        sort($locationIds);

        return [
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'name' => $this->name,
            'description' => $this->description,
            'is_national' => $this->is_national,
            'applies_to_all' => $this->applies_to_all,
            'location_ids' => $locationIds,
        ];
    }

    /** @return list<int> */
    public function locationIds(): array
    {
        $ids = $this->locations()->pluck('locations.id')->map(fn ($id): int => (int) $id)->all();
        sort($ids);

        return $ids;
    }

    /**
     * Batasi ke libur yang berlaku di lokasi (semua lokasi ∪ pivot). Dipakai juga filter Lokasi di
     * HolidayListing agar daftar dan resolver memakai aturan yang sama.
     *
     * @param  Builder<*>  $query
     */
    public static function constrainToLocation(Builder $query, ?int $locationId): void
    {
        $query->where(function (Builder $q) use ($locationId) {
            $q->where($q->qualifyColumn('applies_to_all'), true);
            if ($locationId !== null) {
                $q->orWhereHas('locations', fn (Builder $l) => $l->whereKey($locationId));
            }
        });
    }
}
