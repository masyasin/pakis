<?php

namespace App\Domain\Accounting\Outbox;

use InvalidArgumentException;

/**
 * Dokumen sumber event akuntansi (`accounting_events.source_type/source_id`). Satu event per
 * dokumen (bukan per baris), jadi tanpa `line_id`; rincian baris ada di `payload.lines`.
 */
final readonly class EventSource
{
    /** Kode tipe dokumen: huruf kecil + garis bawah, maks 60 karakter (mis. `goods_receipt`). */
    public const TYPE_PATTERN = '/^[a-z][a-z0-9_]{0,59}$/';

    public function __construct(
        public string $type,
        public int $id,
    ) {
        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new InvalidArgumentException("Tipe sumber event tidak sah [{$type}].");
        }
        if ($id < 1) {
            throw new InvalidArgumentException('ID sumber event harus bilangan bulat positif.');
        }
    }
}
