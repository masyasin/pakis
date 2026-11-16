<?php

namespace Tests\Support;

use App\Domain\Fleet\Actions\SaveVehicle;
use App\Domain\Fleet\Models\Vehicle;
use App\Domain\Inventory\Actions\SaveStockLocation;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Support\StockBalanceProbe;
use App\Domain\Organization\Models\Location;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;

/**
 * Data uji lokasi stok, rak, kendaraan (T-068). Butuh ReferenceSeeder (tipe lokasi). Pembuatan
 * lewat Action agar invarian (Q-01, lokasi stok canvas) sama dengan jalur layar.
 */
final class StockWorld
{
    public static function location(string $code, array $attributes = []): Location
    {
        return Location::query()->create([
            'code' => $code,
            'name' => 'Lokasi '.$code,
            'location_type_id' => ReferenceItem::query()->forGroup(ReferenceGroup::LocationType)->where('code', 'warehouse')->value('id'),
            'is_active' => true,
            ...$attributes,
        ]);
    }

    public static function stockLocation(Location $location, string $code, string $type = 'warehouse', array $attributes = []): StockLocation
    {
        return app(SaveStockLocation::class)->handle(null, [
            'code' => $code,
            'name' => 'Stok '.$code,
            'type' => $type,
            'location_id' => $location->id,
            'is_sellable' => true,
            'is_active' => true,
            ...$attributes,
        ], null);
    }

    public static function vehicle(string $plate, ?Location $home = null, bool $canvas = true, array $attributes = []): Vehicle
    {
        return app(SaveVehicle::class)->handle(null, [
            'plate_no' => $plate,
            'name' => 'Canvas '.$plate,
            'vehicle_type' => 'pickup',
            'is_canvas' => $canvas,
            'home_location_id' => $home?->id,
            'is_active' => true,
            ...$attributes,
        ], null);
    }

    public static function transit(): StockLocation
    {
        return StockLocation::query()->where('code', StockLocation::TRANSIT_CODE)->sole();
    }

    /** Pura-pura ada saldo (buku stok baru ada di T-077) untuk menguji penolakan nonaktif. */
    public static function fakeBalance(bool $hasStock = true): void
    {
        app()->instance(StockBalanceProbe::class, new class($hasStock) implements StockBalanceProbe
        {
            public function __construct(private readonly bool $hasStock) {}

            public function locationHasStock(StockLocation $location): bool
            {
                return $this->hasStock;
            }

            public function binHasStock(StockBin $bin): bool
            {
                return $this->hasStock;
            }
        });
    }
}
