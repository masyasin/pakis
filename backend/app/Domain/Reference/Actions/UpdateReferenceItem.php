<?php

namespace App\Domain\Reference\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reference\Models\ReferenceItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ubah item referensi (BR-026). Kode item sistem dipakai logika program, jadi tidak boleh berubah;
 * nama, deskripsi, dan status aktifnya tetap bisa diubah.
 */
final class UpdateReferenceItem
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{code: string, name: string, description?: string|null, is_active: bool}  $data  sudah divalidasi
     */
    public function handle(ReferenceItem $item, array $data, ?int $userId): ReferenceItem
    {
        if ($item->is_system && $data['code'] !== $item->code) {
            throw ValidationException::withMessages(['code' => 'Kode item sistem tidak bisa diubah karena dipakai program.']);
        }

        return DB::transaction(function () use ($item, $data, $userId) {
            $fields = ['code', 'name', 'description', 'is_active'];
            $before = $item->only($fields);

            $item->fill([
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'],
                'updated_by' => $userId,
            ])->save();

            $this->audit->record('reference.updated', $item, $before, $item->only($fields));

            return $item;
        });
    }
}
