<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Ubah shift (FR-031). Nonaktif = tidak bisa dipilih di pola jadwal baru; jadwal lama tetap
 * menampilkan shift-nya.
 */
final class UpdateShift
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  sudah divalidasi ShiftRequest (kunci = Shift::AUDITED)
     */
    public function handle(Shift $shift, array $data, ?int $userId): Shift
    {
        return DB::transaction(function () use ($shift, $data, $userId) {
            $locked = Shift::query()->lockForUpdate()->findOrFail($shift->id);
            $before = $locked->only(Shift::AUDITED);

            $locked->fill($data);
            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->updated_by = $userId;
            $locked->save();

            $this->audit->record('shift.updated', $locked, $before, $locked->only(Shift::AUDITED));

            return $locked;
        });
    }
}
