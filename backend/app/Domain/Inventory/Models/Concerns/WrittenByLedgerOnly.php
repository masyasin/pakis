<?php

namespace App\Domain\Inventory\Models\Concerns;

/**
 * Model buku stok yang baca-saja dari luar StockLedger (SEC-102). Ledger menulis lewat
 * `DB::table()` lalu menghidrasi model dari baris yang ditulis (`newFromBuilder`), jadi model ini
 * tidak pernah perlu `save()`. Pasangannya: atribut `#[UseEloquentBuilder(LedgerOnlyBuilder::class)]`
 * di model (tulis massal lewat query Eloquent).
 */
trait WrittenByLedgerOnly
{
    /** @param  array<string, mixed>  $options */
    public function save(array $options = []): bool
    {
        LedgerOnlyBuilder::refuse();
    }

    public function delete(): ?bool
    {
        LedgerOnlyBuilder::refuse();
    }

    public function forceDelete(): ?bool
    {
        LedgerOnlyBuilder::refuse();
    }
}
