<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\BinZone;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Support\StockBalanceProbe;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Tambah / ubah lokasi stok dari form (15 §5 `admin.stock-locations.*`, FR-A03).
 *
 * - Form hanya membuat gudang & toko; tipe tidak bisa diubah setelah dibuat (keputusan T-068, PA-43).
 * - Gudang/toko baru langsung mendapat rak karantina `Q-01` (15 §4.3).
 * - Lokasi stok kendaraan dikelola lewat kendaraannya (SaveVehicle); dari sini hanya `is_sellable`.
 * - TRANSIT tidak pernah diubah (Policy menolak 403; di sini pengaman kedua).
 * - Nonaktif ditolak bila masih bersaldo (StockBalanceProbe, aktif setelah T-077).
 */
final class SaveStockLocation
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StockBalanceProbe $balances,
    ) {}

    /**
     * @param  array<string, mixed>  $data  sudah divalidasi StockLocationRequest
     */
    public function handle(?StockLocation $location, array $data, ?int $userId): StockLocation
    {
        return DB::transaction(fn () => $location === null
            ? $this->create($data, $userId)
            : $this->update($location, $data, $userId));
    }

    /** @param  array<string, mixed>  $data */
    private function create(array $data, ?int $userId): StockLocation
    {
        $type = StockLocationType::from((string) $data['type']);
        if (! in_array($type, StockLocationType::manual(), true)) {
            throw new LogicException('Form lokasi stok hanya membuat gudang/toko.');
        }

        $location = StockLocation::query()->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $type,
            'location_id' => $data['location_id'],
            'vehicle_id' => null,
            'is_sellable' => (bool) $data['is_sellable'],
            'is_system' => false,
            'is_active' => (bool) $data['is_active'],
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $this->audit->record('stock_location.created', $location, null, self::snapshot($location));

        $bin = StockBin::query()->create([
            'stock_location_id' => $location->id,
            'code' => StockBin::QUARANTINE_CODE,
            'name' => 'Karantina',
            'zone' => BinZone::Quarantine,
            'is_active' => true,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        $this->audit->record('stock_bin.created', $bin, null, SaveStockBin::snapshot($bin));

        return $location;
    }

    /** @param  array<string, mixed>  $data */
    private function update(StockLocation $location, array $data, ?int $userId): StockLocation
    {
        $locked = StockLocation::query()->lockForUpdate()->findOrFail($location->id);
        if ($locked->isProtected()) {
            throw new ApiError(ErrorCode::FORBIDDEN);
        }

        $before = self::snapshot($locked);

        // Lokasi stok kendaraan: kode, nama, lokasi asal, dan status mengikuti kendaraannya.
        $editable = $locked->type === StockLocationType::Vehicle
            ? ['is_sellable']
            : ['code', 'name', 'location_id', 'is_sellable', 'is_active'];
        $locked->fill(array_intersect_key($data, array_flip($editable)));

        if (! $locked->isDirty()) {
            return $locked;
        }

        if ($locked->isDirty('is_active') && ! $locked->is_active && $this->balances->locationHasStock($locked)) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, publicMessage: 'Lokasi stok masih menyimpan barang. Pindahkan atau habiskan stoknya dulu sebelum menonaktifkan.');
        }

        $locked->updated_by = $userId;
        $locked->save();

        $this->audit->record('stock_location.updated', $locked, $before, self::snapshot($locked));

        return $locked;
    }

    /** @return array<string, mixed> */
    public static function snapshot(StockLocation $location): array
    {
        $values = $location->only(StockLocation::AUDITED);
        $values['type'] = $location->type->value;

        return $values;
    }
}
