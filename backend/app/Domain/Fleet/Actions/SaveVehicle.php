<?php

namespace App\Domain\Fleet\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Fleet\Models\Vehicle;
use App\Domain\Inventory\Actions\SaveStockLocation;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Support\StockBalanceProbe;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Tambah / ubah kendaraan (15 §5 `admin.vehicles.*`, FR-A05) dan sinkronkan lokasi stok canvasnya
 * di transaksi yang sama (ADR-107: setiap kendaraan canvas punya tepat satu lokasi stok `vehicle`).
 *
 * - Canvas & aktif → lokasi stok ada & aktif; kode `KDR-{plat}`, nama, dan `location_id` (= lokasi
 *   asal, agar terlihat oleh kepala gudang/lokasi setempat dan jadi induk persetujuan, PA-43)
 *   mengikuti kendaraan.
 * - Centang canvas dicabut atau kendaraan dinonaktifkan → lokasi stoknya dinonaktifkan; ditolak
 *   409 bila masih bersaldo (StockBalanceProbe, T-077). Dicentang lagi → baris yang SAMA diaktifkan
 *   kembali (unique `vehicle_id`), sehingga riwayat stoknya tidak terpecah.
 */
final class SaveVehicle
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StockBalanceProbe $balances,
    ) {}

    /**
     * @param  array<string, mixed>  $data  sudah divalidasi VehicleRequest (kunci = Vehicle::AUDITED)
     */
    public function handle(?Vehicle $vehicle, array $data, ?int $userId): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $data, $userId) {
            if ($vehicle === null) {
                $saved = Vehicle::query()->create([...$data, 'created_by' => $userId, 'updated_by' => $userId]);
                $this->audit->record('vehicle.created', $saved, null, self::snapshot($saved));
            } else {
                $saved = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);
                $before = self::snapshot($saved);
                $saved->fill($data);

                if ($saved->isDirty()) {
                    $saved->updated_by = $userId;
                    $saved->save();
                    $this->audit->record('vehicle.updated', $saved, $before, self::snapshot($saved));
                }
            }

            $this->syncStockLocation($saved, $userId);

            return $saved;
        });
    }

    private function syncStockLocation(Vehicle $vehicle, ?int $userId): void
    {
        $stock = StockLocation::withTrashed()->where('vehicle_id', $vehicle->id)->lockForUpdate()->first();
        $wanted = $vehicle->is_canvas && $vehicle->is_active;

        if ($stock === null) {
            if (! $wanted) {
                return;
            }

            $stock = StockLocation::query()->create([
                'code' => StockLocation::vehicleCode($vehicle->plate_no),
                'name' => $vehicle->name,
                'type' => StockLocationType::Vehicle,
                'location_id' => $vehicle->home_location_id,
                'vehicle_id' => $vehicle->id,
                'is_sellable' => true,
                'is_system' => false,
                'is_active' => true,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $this->audit->record('stock_location.created', $stock, null, SaveStockLocation::snapshot($stock));

            return;
        }

        $before = SaveStockLocation::snapshot($stock);
        $stock->fill([
            'code' => StockLocation::vehicleCode($vehicle->plate_no),
            'name' => $vehicle->name,
            'location_id' => $vehicle->home_location_id,
            'is_active' => $wanted,
        ]);
        if ($stock->trashed()) {
            $stock->deleted_at = null;
        }

        if (! $stock->isDirty()) {
            return;
        }

        if ($stock->isDirty('is_active') && ! $wanted && $this->balances->locationHasStock($stock)) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, publicMessage: 'Stok di kendaraan ini belum kosong. Bongkar muatan canvas dulu sebelum mencabut canvas atau menonaktifkan kendaraan.');
        }

        $stock->updated_by = $userId;
        $stock->save();
        $this->audit->record('stock_location.updated', $stock, $before, SaveStockLocation::snapshot($stock));
    }

    /** @return array<string, mixed> */
    public static function snapshot(Vehicle $vehicle): array
    {
        $values = $vehicle->only(Vehicle::AUDITED);
        $values['vehicle_type'] = $vehicle->vehicle_type->value;

        return $values;
    }
}
