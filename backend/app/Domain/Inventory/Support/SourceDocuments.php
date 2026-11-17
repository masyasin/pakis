<?php

namespace App\Domain\Inventory\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dokumen sumber mutasi stok (`stock_movements.source_type/source_id`, 15 §4.8) → nomor & tautan
 * halaman, untuk kartu stok & telusur batch (T-087). Tipe tanpa halaman (mis. `stock_quarantine`
 * = batch asal karantina) tampil tanpa tautan. Paket B/C menambah tipe penjualan/canvas di sini.
 */
final class SourceDocuments
{
    /** tipe sumber => [tabel, awalan URL] */
    private const DOCUMENTS = [
        'goods_receipt' => ['goods_receipts', '/penerimaan/'],
        'purchase_return' => ['purchase_returns', '/pembelian/retur/'],
        'stock_transfer' => ['stock_transfers', '/mutasi/'],
        'stock_opname' => ['stock_opnames', '/opname/'],
        'disposal_report' => ['disposal_reports', '/pemusnahan/'],
    ];

    private const LABELS = [
        'stock_quarantine' => 'Karantina manual',
        'stock_quarantine_release' => 'Lepas karantina',
        'stock_bin_move' => 'Pindah rak',
        // Saldo awal (T-086): source_id = id baris impor, bukan dokumen yang bisa dibuka.
        'opening_stock' => 'Saldo awal',
    ];

    /**
     * @param  Collection<int, object{source_type: string, source_id: int}>  $movements
     * @return array<string, array{number: string, url: string|null}> kunci "tipe:id"
     */
    public static function resolve(Collection $movements): array
    {
        $out = [];
        foreach ($movements->groupBy('source_type') as $type => $rows) {
            $ids = $rows->pluck('source_id')->unique()->values()->all();
            if (isset(self::DOCUMENTS[$type])) {
                [$table, $url] = self::DOCUMENTS[$type];
                foreach (DB::table($table)->whereIn('id', $ids)->pluck('number', 'id') as $id => $number) {
                    $out["{$type}:{$id}"] = ['number' => (string) $number, 'url' => $url.$id];
                }
            }
            foreach ($ids as $id) {
                $out["{$type}:{$id}"] ??= ['number' => self::LABELS[$type] ?? $type, 'url' => null];
            }
        }

        return $out;
    }

    /**
     * Pencarian kartu stok: mutasi yang dokumen sumbernya bernomor seperti pola (nilai sudah di-escape).
     *
     * @param  Builder<*>  $query
     */
    public static function whereNumberLike(Builder $query, string $pattern): void
    {
        foreach (self::DOCUMENTS as $type => [$table]) {
            $query->orWhere(fn (Builder $q) => $q->where('stock_movements.source_type', $type)
                ->whereIn('stock_movements.source_id', fn ($s) => $s->select('id')->from($table)->where('number', 'like', $pattern)));
        }
    }
}
