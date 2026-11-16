<?php

namespace Database\Seeders;

use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Models\StockLocation;
use Illuminate\Database\Seeder;

/**
 * Lokasi stok sistem `TRANSIT` (15 §4.3, T-068): tempat barang selama mutasi antar lokasi (T-082).
 * Idempoten: dipanggil DatabaseSeeder (instalasi baru) dan migrasi data (DB yang sudah berjalan).
 * Baris yang sudah ada dikembalikan ke bentuk sistemnya, tidak pernah digandakan.
 */
class TransitStockLocationSeeder extends Seeder
{
    public function run(): void
    {
        $transit = StockLocation::withTrashed()->firstOrNew(['code' => StockLocation::TRANSIT_CODE]);

        $transit->forceFill([
            'name' => $transit->exists ? $transit->name : 'Barang dalam perjalanan',
            'type' => StockLocationType::InTransit,
            'location_id' => null,
            'vehicle_id' => null,
            'is_sellable' => false,
            'is_system' => true,
            'is_active' => true,
            'deleted_at' => null,
        ]);

        if ($transit->isDirty()) {
            $transit->save();
        }
    }
}
