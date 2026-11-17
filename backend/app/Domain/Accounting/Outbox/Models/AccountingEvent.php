<?php

namespace App\Domain\Accounting\Outbox\Models;

use App\Domain\Accounting\Outbox\EventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Satu event outbox akuntansi (15 §4.8, ADR-104). Ditulis HANYA lewat AccountingOutbox::record().
 *
 * Append-only: hanya kolom mesin posting Paket F (`status`, `journal_entry_id`, `error`) yang
 * boleh berubah; selebihnya ditolak di sini dan oleh trigger DB. Menghapus selalu ditolak.
 *
 * @property int $id
 * @property string $event_code
 * @property Carbon $business_date
 * @property string $source_type
 * @property int $source_id
 * @property int $revision
 * @property array{lines: list<array<string, mixed>>, refs?: array<string, mixed>, memo?: string} $payload
 * @property EventStatus $status
 * @property int|null $journal_entry_id
 * @property string|null $error
 * @property Carbon|null $created_at
 */
class AccountingEvent extends Model
{
    /** Kolom yang boleh diubah setelah insert (sama dengan pengecualian trigger). */
    public const MUTABLE = ['status', 'journal_entry_id', 'error'];

    public const UPDATED_AT = null;

    protected $table = 'accounting_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'source_id' => 'integer',
            'revision' => 'integer',
            'payload' => 'array',
            'status' => EventStatus::class,
            'journal_entry_id' => 'integer',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            $illegal = array_diff(array_keys($this->getDirty()), self::MUTABLE);
            if ($illegal !== []) {
                throw new LogicException('accounting_events append-only: kolom ['.implode(', ', $illegal).'] tidak boleh diubah. Koreksi lewat event revisi baru.');
            }
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new LogicException('accounting_events append-only: event tidak boleh dihapus.');
    }
}
