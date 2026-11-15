<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Support\ShiftUsage;
use App\Domain\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hapus shift (05 §4.9): hanya shift yang belum pernah dipakai jadwal/absensi. Yang sudah dipakai
 * cukup dinonaktifkan agar jadwal & absensi lama tetap menampilkan shift-nya.
 */
final class DeleteShift
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ShiftUsage $usage,
    ) {}

    public function handle(Shift $shift, ?int $userId): void
    {
        DB::transaction(function () use ($shift, $userId) {
            // Kunci baris dulu: pemakaian baru tidak bisa menyelip di antara cek & hapus.
            $locked = Shift::query()->lockForUpdate()->findOrFail($shift->id);

            if ($this->usage->isUsed($locked)) {
                throw ValidationException::withMessages([
                    'shift' => "Shift \"{$locked->name}\" sudah dipakai jadwal atau presensi sehingga tidak bisa dihapus. Nonaktifkan saja.",
                ]);
            }

            $before = $locked->only(Shift::AUDITED);
            $locked->updated_by = $userId;
            $locked->save();
            $locked->delete();

            $this->audit->record('shift.deleted', $locked, $before, null);
        });
    }
}
