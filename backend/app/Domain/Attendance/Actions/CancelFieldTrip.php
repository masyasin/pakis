<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Enums\FieldTripStatus;
use App\Domain\Attendance\Models\FieldTrip;
use App\Domain\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Batalkan tugas `approved` (06 §7.2a). Tugas tidak dihapus agar riwayat & `flags.field_trip_id`
 * di log absen tetap merujuk ke data yang ada. Absen yang sudah diterima tidak berubah.
 */
final class CancelFieldTrip
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(FieldTrip $trip, ?int $userId): FieldTrip
    {
        return DB::transaction(function () use ($trip, $userId) {
            $locked = FieldTrip::query()->lockForUpdate()->findOrFail($trip->id);
            if ($locked->status !== FieldTripStatus::Approved) {
                throw ValidationException::withMessages(['trip' => 'Tugas ini sudah tidak aktif.']);
            }

            $locked->status = FieldTripStatus::Cancelled;
            $locked->updated_by = $userId;
            $locked->save();

            $this->audit->record('field_trip.cancelled', $locked, ['status' => FieldTripStatus::Approved->value], ['status' => FieldTripStatus::Cancelled->value]);

            return $locked;
        });
    }
}
