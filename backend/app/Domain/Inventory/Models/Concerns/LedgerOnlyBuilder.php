<?php

namespace App\Domain\Inventory\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Query Eloquent baca-saja untuk tabel buku stok (SEC-102, ADR-103). Saldo & mutasi hanya ditulis
 * `App\Domain\Inventory\Ledger\StockLedger` lewat query builder biasa di dalam transaksi berkunci,
 * jadi setiap jalan tulis lewat model (update massal, increment, insert, delete) ditolak di sini.
 * Jalan pintas `DB::table('stock_*')`/SQL mentah dijaga pemindaian statis StockLedgerArchTest.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
final class LedgerOnlyBuilder extends Builder
{
    /** Metode query builder dasar yang diteruskan lewat __call tetapi menulis data. */
    private const BLOCKED_FORWARDS = [
        'insert', 'insertgetid', 'insertorignore', 'insertorignorereturning', 'insertusing', 'insertorignoreusing',
        'updateorinsert', 'updatefrom', 'truncate',
    ];

    public function update(array $values)
    {
        self::refuse();
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        self::refuse();
    }

    /** @param  string|null  $column */
    public function touch($column = null)
    {
        self::refuse();
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        self::refuse();
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        self::refuse();
    }

    public function incrementEach(array $columns, array $extra = [])
    {
        self::refuse();
    }

    public function decrementEach(array $columns, array $extra = [])
    {
        self::refuse();
    }

    public function delete()
    {
        self::refuse();
    }

    public function forceDelete()
    {
        self::refuse();
    }

    /**
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     */
    public function __call($method, $parameters)
    {
        if (in_array(strtolower($method), self::BLOCKED_FORWARDS, true)) {
            self::refuse();
        }

        return parent::__call($method, $parameters);
    }

    public static function refuse(): never
    {
        throw new LogicException('Buku stok hanya boleh ditulis lewat StockLedger (SEC-102).');
    }
}
