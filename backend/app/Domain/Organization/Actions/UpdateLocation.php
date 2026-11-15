<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\Models\Location;
use Illuminate\Support\Facades\DB;

/** Ubah lokasi (FR-022). Nonaktif = tidak bisa dipilih di penugasan baru (BR-041). */
final class UpdateLocation
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  sudah divalidasi LocationRequest (kunci = Location::AUDITED)
     */
    public function handle(Location $location, array $data, ?int $userId): Location
    {
        return DB::transaction(function () use ($location, $data, $userId) {
            $locked = Location::query()->lockForUpdate()->findOrFail($location->id);
            $before = $locked->only(Location::AUDITED);

            $locked->fill($data);
            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->updated_by = $userId;
            $locked->save();

            $this->audit->record('location.updated', $locked, $before, $locked->only(Location::AUDITED));

            return $locked;
        });
    }
}
