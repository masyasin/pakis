<?php

namespace App\Domain\Reference\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use Illuminate\Support\Facades\DB;

/**
 * Tambah item referensi (BR-026). Item dari layar tidak pernah `is_system`, dan masuk di urutan
 * paling akhir grupnya.
 */
final class CreateReferenceItem
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{code: string, name: string, description?: string|null, is_active?: bool}  $data  sudah divalidasi
     */
    public function handle(ReferenceGroup $group, array $data, ?int $userId): ReferenceItem
    {
        return DB::transaction(function () use ($group, $data, $userId) {
            $last = (int) ReferenceItem::query()->forGroup($group)->lockForUpdate()->max('sort_order');

            $item = ReferenceItem::query()->create([
                'group_code' => $group->value,
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'is_system' => false,
                'sort_order' => min($last + 10, 65535),
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->audit->record('reference.created', $item, null, $item->only(['group_code', 'code', 'name', 'description', 'is_active']));

            return $item;
        });
    }
}
