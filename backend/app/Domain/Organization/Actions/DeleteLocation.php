<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Support\LocationUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hapus lokasi (BR-041): hanya lokasi yang belum pernah dipakai penugasan/absensi/hari libur.
 * Yang sudah dipakai cukup dinonaktifkan agar data lama tetap menampilkan lokasinya.
 */
final class DeleteLocation
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LocationUsage $usage,
    ) {}

    public function handle(Location $location, ?int $userId): void
    {
        DB::transaction(function () use ($location, $userId) {
            // Kunci baris dulu: pemakaian baru tidak bisa menyelip di antara cek & hapus.
            $locked = Location::query()->lockForUpdate()->findOrFail($location->id);

            if ($this->usage->isUsed($locked)) {
                throw ValidationException::withMessages([
                    'location' => "Lokasi \"{$locked->name}\" sudah dipakai penugasan atau absensi sehingga tidak bisa dihapus. Nonaktifkan saja.",
                ]);
            }

            $before = $locked->only(Location::AUDITED);
            $locked->updated_by = $userId;
            $locked->save();
            $locked->delete();

            $this->audit->record('location.deleted', $locked, $before, null);
        });
    }
}
