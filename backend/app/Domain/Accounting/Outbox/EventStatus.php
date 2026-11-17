<?php

namespace App\Domain\Accounting\Outbox;

/** Status event outbox (15 §4.8). Nilai identik dengan ENUM `accounting_events.status`. */
enum EventStatus: string
{
    /** Belum diposting; satu-satunya status yang ditulis Paket A–E (mesin posting = Paket F). */
    case Pending = 'pending';
    case Posted = 'posted';
    /** Event tanpa baris jurnal (`lines: []`, mis. `inventory.transferred`). */
    case Skipped = 'skipped';
    case Failed = 'failed';
}
