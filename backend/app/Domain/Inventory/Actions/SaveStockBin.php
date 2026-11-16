<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\BinZone;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Support\StockBalanceProbe;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Tambah / ubah rak (15 §5 `admin.stock-bins.*`, FR-A04). Validasi bentuk (lokasi gudang/toko, kode
 * unik per lokasi, Q-01 terkunci) di StockBinRequest; di sini aturan yang butuh kunci baris:
 * rak bersaldo tidak bisa dinonaktifkan, dan rak tidak pernah pindah lokasi stok.
 */
final class SaveStockBin
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StockBalanceProbe $balances,
    ) {}

    /**
     * @param  array<string, mixed>  $data  sudah divalidasi StockBinRequest
     */
    public function handle(?StockBin $bin, array $data, ?int $userId): StockBin
    {
        return DB::transaction(function () use ($bin, $data, $userId) {
            if ($bin === null) {
                // Kunci lokasi stok: tidak ada rak baru selagi lokasinya sedang diubah tipenya/statusnya.
                $location = StockLocation::query()->lockForUpdate()->findOrFail((int) $data['stock_location_id']);

                $created = StockBin::query()->create([
                    'stock_location_id' => $location->id,
                    'code' => $data['code'],
                    'name' => $data['name'] ?? null,
                    'zone' => BinZone::from((string) $data['zone']),
                    'is_active' => (bool) $data['is_active'],
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);
                $this->audit->record('stock_bin.created', $created, null, self::snapshot($created));

                return $created;
            }

            $locked = StockBin::query()->lockForUpdate()->findOrFail($bin->id);
            $before = self::snapshot($locked);

            $changes = array_intersect_key($data, array_flip(['code', 'name', 'zone', 'is_active']));
            if ($locked->isDefaultQuarantine()) {
                // Q-01 = tujuan karantina otomatis (T-078/T-084): hanya namanya yang boleh diubah.
                $changes = array_intersect_key($changes, array_flip(['name']));
            }
            $locked->fill($changes);

            if (! $locked->isDirty()) {
                return $locked;
            }

            if ($locked->isDirty('is_active') && ! $locked->is_active && $this->balances->binHasStock($locked)) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, publicMessage: 'Rak masih menyimpan barang. Pindahkan stoknya ke rak lain dulu sebelum menonaktifkan.');
            }

            $locked->updated_by = $userId;
            $locked->save();
            $this->audit->record('stock_bin.updated', $locked, $before, self::snapshot($locked));

            return $locked;
        });
    }

    /** @return array<string, mixed> */
    public static function snapshot(StockBin $bin): array
    {
        $values = $bin->only(StockBin::AUDITED);
        $values['zone'] = $bin->zone->value;

        return $values;
    }
}
