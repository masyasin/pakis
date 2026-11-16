<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Models\SalesArea;
use App\Domain\Customer\Support\SalesAreaTree;
use Illuminate\Support\Facades\DB;

/**
 * Tambah / ubah area penjualan dari dialog daftar (15 §5 `admin.sales-areas.store/update`, FR-A12).
 * Induk dicek tanpa siklus & maks 3 tingkat di dalam kunci tabel (SalesAreaTree); daftar kecamatan
 * disinkronkan dan ikut tercatat di audit `sales_area.created|updated`. Tanpa hapus: nonaktif.
 */
final class SaveSalesArea
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  sudah divalidasi SalesAreaRequest (`district_codes` = list<string>)
     */
    public function handle(?SalesArea $area, array $data, ?int $userId): SalesArea
    {
        return DB::transaction(function () use ($area, $data, $userId) {
            SalesAreaTree::lock();

            $locked = $area === null ? new SalesArea : SalesArea::query()->lockForUpdate()->findOrFail($area->id);
            $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
            SalesAreaTree::assertParent($locked->exists ? $locked->id : null, $parentId);

            $before = $locked->exists ? self::snapshot($locked) : null;

            $locked->fill(array_intersect_key($data, array_flip(SalesArea::AUDITED)));
            $locked->parent_id = $parentId;
            $locked->updated_by = $userId;
            if (! $locked->exists) {
                $locked->created_by = $userId;
            }
            $locked->save();

            /** @var list<string> $districts */
            $districts = array_values(array_unique($data['district_codes'] ?? []));
            sort($districts);
            $locked->districts()->sync($districts);

            $after = self::snapshot($locked);
            if ($before === null) {
                $this->audit->record('sales_area.created', $locked, null, $after);
            } elseif ($before !== $after) {
                $this->audit->record('sales_area.updated', $locked, $before, $after);
            }

            return $locked;
        });
    }

    /** @return array<string, mixed> */
    public static function snapshot(SalesArea $area): array
    {
        $values = $area->only(SalesArea::AUDITED);
        $values['district_codes'] = DB::table('sales_area_districts')->where('sales_area_id', $area->id)
            ->orderBy('district_code')->pluck('district_code')->all();

        return $values;
    }
}
