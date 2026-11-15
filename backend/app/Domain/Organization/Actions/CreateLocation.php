<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\Models\Location;
use Illuminate\Support\Facades\DB;

/** Tambah lokasi (FR-022). */
final class CreateLocation
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  sudah divalidasi LocationRequest (kunci = Location::AUDITED)
     */
    public function handle(array $data, ?int $userId): Location
    {
        return DB::transaction(function () use ($data, $userId) {
            $location = Location::query()->create([
                ...$data,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->audit->record('location.created', $location, null, $location->only(Location::AUDITED));

            return $location;
        });
    }
}
