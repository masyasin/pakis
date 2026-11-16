<?php

namespace App\Domain\Catalog\Units;

/**
 * Satuan produk untuk konversi (BR-150): "1 satuan ini = factor satuan dasar". Diimplementasikan
 * model `ProductUnit` di T-070; sebelum itu converter diuji dengan objek in-memory.
 */
interface ProductUnitLike
{
    /** Faktor ke satuan dasar sebagai string desimal (> 0, maks 3 desimal), mis. "24". */
    public function conversionFactor(): string;

    /** Satuan hanya menerima bilangan bulat (meta referensi `unit.integer_only`). */
    public function isIntegerOnly(): bool;

    /** Nama satuan untuk pesan, mis. "Botol". */
    public function unitLabel(): string;
}
