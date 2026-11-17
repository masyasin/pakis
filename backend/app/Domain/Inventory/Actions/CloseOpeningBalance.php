<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Models\StockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Tutup saldo awal stok (T-086, BR-170). Dipanggil `UpdateSettingGroup` di transaksi yang sama saat
 * `inventory.opening_balance_open` berubah true → false (Owner, konfirmasi password), jadi audit
 * penutupan selalu tercatat bersama perubahan setting-nya. Penanda `one_way` mencegah dibuka lagi.
 * Audit memuat jumlah mutasi saldo awal + total nilainya agar bisa dicocokkan dengan pembukuan lama.
 */
final class CloseOpeningBalance
{
    public const SETTING_KEY = 'inventory.opening_balance_open';

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(?int $userId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('CloseOpeningBalance wajib dipanggil di dalam transaksi perubahan setting.');
        }

        $total = BigDecimal::zero();
        $count = 0;
        StockMovement::query()->where('movement_type', MovementType::OpeningBalance->value)
            ->select(['id', 'qty', 'unit_cost'])->orderBy('id')
            ->chunk(5000, function ($rows) use (&$total, &$count) {
                foreach ($rows as $m) {
                    $total = $total->plus(BigDecimal::of($m->qty)->multipliedBy($m->unit_cost));
                    $count++;
                }
            });

        $this->audit->record('inventory.opening_balance_closed', null, [self::SETTING_KEY => true], [
            self::SETTING_KEY => false,
            'movements' => $count,
            'total_value' => (string) $total->toScale(2, RoundingMode::HalfUp),
            'closed_by' => $userId,
        ]);
    }
}
