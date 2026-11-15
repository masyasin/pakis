<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\Models\Position;
use App\Domain\Organization\Support\PositionUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hapus jabatan: hanya yang belum punya jabatan bawahan dan belum dipakai penugasan/absensi.
 * Yang sudah dipakai cukup dinonaktifkan agar data lama tetap menampilkan namanya.
 */
final class DeletePosition
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PositionUsage $usage,
    ) {}

    public function handle(Position $position, ?int $userId): void
    {
        DB::transaction(function () use ($position, $userId) {
            $locked = Position::query()->lockForUpdate()->findOrFail($position->id);

            if ($this->usage->isUsed($locked)) {
                throw ValidationException::withMessages([
                    'position' => "Jabatan \"{$locked->name}\" masih menjadi atasan jabatan lain atau sudah dipakai penugasan/absensi sehingga tidak bisa dihapus. Nonaktifkan saja.",
                ]);
            }

            $before = CreatePosition::audited($locked);
            $locked->updated_by = $userId;
            $locked->save();
            $locked->delete();

            $this->audit->record('position.deleted', $locked, $before, null);
        });
    }
}
