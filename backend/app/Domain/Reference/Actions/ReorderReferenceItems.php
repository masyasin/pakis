<?php

namespace App\Domain\Reference\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Atur ulang urutan satu grup. Daftar id harus persis semua item grup itu (tanpa duplikat, tanpa
 * item grup lain), agar urutan tidak pernah setengah jadi.
 */
final class ReorderReferenceItems
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<int>  $ids  urutan baru, item pertama paling atas
     */
    public function handle(ReferenceGroup $group, array $ids, ?int $userId): void
    {
        DB::transaction(function () use ($group, $ids, $userId) {
            $items = ReferenceItem::query()->forGroup($group)->lockForUpdate()->get()->keyBy('id');

            $expected = $items->keys()->sort()->values()->all();
            $given = collect($ids)->sort()->values()->all();
            if (count($ids) !== count(array_unique($ids)) || $expected !== $given) {
                throw ValidationException::withMessages(['ids' => 'Urutan harus memuat semua item grup ini tepat satu kali. Muat ulang halaman lalu coba lagi.']);
            }

            $before = $items->sortBy('sort_order')->pluck('code')->values()->all();

            foreach ($ids as $index => $id) {
                $item = $items[$id];
                $order = ($index + 1) * 10;
                if ($item->sort_order !== $order) {
                    $item->forceFill(['sort_order' => $order, 'updated_by' => $userId])->save();
                }
            }

            $after = array_map(fn (int $id) => $items[$id]->code, $ids);
            $this->audit->record('reference.reordered', null, ['group' => $group->value, 'order' => $before], ['group' => $group->value, 'order' => $after]);
        });
    }
}
