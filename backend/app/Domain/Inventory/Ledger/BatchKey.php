<?php

namespace App\Domain\Inventory\Ledger;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * Identitas batch di dalam satu lokasi stok + produk (`sbatch_unique`: lokasi, produk, nomor batch,
 * rak). ED ikut dibawa karena batch baru dibuat dengan ED ini; ED tidak termasuk kunci unik.
 */
final readonly class BatchKey
{
    /** Nomor batch untuk produk yang tidak melacak batch (15 §4.8). */
    public const NO_BATCH = '-';

    public ?string $expiryDate;

    public function __construct(
        public string $batchNo,
        DateTimeInterface|string|null $expiryDate = null,
        public ?int $binId = null,
    ) {
        if (trim($batchNo) !== $batchNo || $batchNo === '' || mb_strlen($batchNo) > 60) {
            throw new InvalidArgumentException('Nomor batch wajib diisi, maks 60 karakter, tanpa spasi di tepi.');
        }
        $this->expiryDate = $expiryDate === null ? null : LedgerDate::normalize($expiryDate);
    }

    public static function none(?int $binId = null): self
    {
        return new self(self::NO_BATCH, null, $binId);
    }
}
