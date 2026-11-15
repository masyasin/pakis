<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Tambah shift (FR-031). Aturan jam (BR-060) sudah divalidasi ShiftRequest dan dijaga CHECK DB. */
final class CreateShift
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  sudah divalidasi ShiftRequest (kunci = Shift::AUDITED)
     */
    public function handle(array $data, ?int $userId): Shift
    {
        return DB::transaction(function () use ($data, $userId) {
            $shift = new Shift($data);
            $shift->created_by = $userId;
            $shift->updated_by = $userId;
            $shift->save();

            $this->audit->record('shift.created', $shift, null, $shift->only(Shift::AUDITED));

            return $shift;
        });
    }
}
