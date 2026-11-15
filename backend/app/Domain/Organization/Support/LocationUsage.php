<?php

namespace App\Domain\Organization\Support;

use App\Domain\Organization\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Apakah lokasi sudah dipakai data lain (BR-041: yang pernah dipakai hanya bisa dinonaktifkan).
 * Tabel pemakai dibuat di T-025 (penugasan), T-034 (hari libur), T-036 (absensi); sebelum ada,
 * pemakai itu dilewati. Setelah ada, FK di DB juga menolak penghapusan — cek ini memberi pesan
 * yang ramah, bukan satu-satunya pengaman. Pola sama dengan ReferenceUsage (T-010).
 */
class LocationUsage
{
    /** @var list<array{0: string, 1: string}> [tabel, kolom] dari 04 */
    public const USAGES = [
        ['assignments', 'location_id'],
        ['holiday_locations', 'location_id'],
        ['attendance_days', 'location_id'],
        ['attendance_logs', 'matched_location_id'],
    ];

    /**
     * @param  list<array{0: string, 1: string}>|null  $usages  pemakai eksplisit (untuk test); null = USAGES
     */
    public function __construct(private readonly ?array $usages = null) {}

    public function isUsed(Location $location): bool
    {
        // Pemakai eksplisit (test) tidak dicek keberadaan tabelnya: MariaDB tidak menampilkan
        // TEMPORARY TABLE di information_schema sehingga Schema::hasTable() selalu false untuknya.
        $usages = $this->usages ?? array_filter(self::USAGES, fn (array $usage) => Schema::hasTable($usage[0]));

        foreach ($usages as [$table, $column]) {
            if (DB::table($table)->where($column, $location->id)->exists()) {
                return true;
            }
        }

        return false;
    }
}
