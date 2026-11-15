<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\Models\Position;
use App\Domain\Organization\Support\PositionHierarchy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Ubah jabatan (FR-024). Atasan jabatan tidak boleh membentuk siklus. */
final class UpdatePosition
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  sudah divalidasi PositionRequest (kunci = Position::AUDITED)
     */
    public function handle(Position $position, array $data, ?int $userId): Position
    {
        return DB::transaction(function () use ($position, $data, $userId) {
            // Kunci semua baris jabatan (tabel kecil): dua perubahan atasan bersamaan (A→B dan B→A)
            // masing-masing lolos cek siklus bila tidak diserialkan.
            DB::table('positions')->lockForUpdate()->pluck('id');
            $locked = Position::query()->findOrFail($position->id);
            $before = CreatePosition::audited($locked);

            $parentId = $data['parent_position_id'] ?? null;
            if ($parentId !== null && $parentId !== $locked->parent_position_id && PositionHierarchy::wouldCycle($locked->id, (int) $parentId)) {
                throw ValidationException::withMessages([
                    'parent_position_id' => 'Jabatan atasan ini berada di bawah jabatan yang sedang diubah, sehingga membentuk lingkaran. Pilih atasan lain.',
                ]);
            }

            $locked->fill($data);
            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->updated_by = $userId;
            $locked->save();

            $this->audit->record('position.updated', $locked, $before, CreatePosition::audited($locked));

            return $locked;
        });
    }
}
