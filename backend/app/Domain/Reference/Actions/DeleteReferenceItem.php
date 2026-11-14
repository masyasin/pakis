<?php

namespace App\Domain\Reference\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reference\Models\ReferenceItem;
use App\Domain\Reference\ReferenceUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hapus item referensi (BR-026): hanya item bukan sistem yang belum dipakai data mana pun.
 * Yang sudah dipakai cukup dinonaktifkan agar data lama tetap menampilkan namanya.
 */
final class DeleteReferenceItem
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ReferenceUsage $usage,
    ) {}

    public function handle(ReferenceItem $item): void
    {
        if ($item->is_system) {
            throw ValidationException::withMessages(['item' => 'Item sistem tidak bisa dihapus. Nonaktifkan saja bila tidak dipakai lagi.']);
        }

        DB::transaction(function () use ($item) {
            // Kunci baris dulu: pemakaian baru tidak bisa menyelip di antara cek & hapus.
            $locked = ReferenceItem::query()->lockForUpdate()->findOrFail($item->id);

            if ($this->usage->isUsed($locked)) {
                throw ValidationException::withMessages(['item' => "\"{$locked->name}\" sudah dipakai data lain sehingga tidak bisa dihapus. Nonaktifkan saja."]);
            }

            $before = $locked->only(['group_code', 'code', 'name', 'description', 'is_active']);
            $locked->delete();

            $this->audit->record('reference.deleted', $locked, $before, null);
        });
    }
}
