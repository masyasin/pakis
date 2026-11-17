<?php

namespace App\Domain\Inventory\Ledger;

use InvalidArgumentException;

/**
 * Dokumen sumber mutasi/reservasi (`source_type`, `source_id`, `source_line_id`, 15 §4.8). Kartu
 * stok menelusuri setiap mutasi ke dokumen ini, jadi tipe memakai kode tetap (mis. `goods_receipt`,
 * `stock_transfer`), bukan nama kelas.
 */
final readonly class Source
{
    /** Huruf kecil + garis bawah, maks 60 karakter (kolom `source_type`). */
    public const TYPE_PATTERN = '/^[a-z][a-z0-9_]{0,59}$/';

    public function __construct(
        public string $type,
        public int $id,
        public ?int $lineId = null,
    ) {
        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new InvalidArgumentException("Tipe sumber mutasi tidak sah [{$type}].");
        }
        if ($id < 1 || ($lineId !== null && $lineId < 1)) {
            throw new InvalidArgumentException('ID sumber mutasi harus bilangan bulat positif.');
        }
    }
}
