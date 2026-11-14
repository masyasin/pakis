<?php

namespace App\Domain\Numbering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Nomor terakhir per (urutan, scope). Diubah HANYA oleh NumberGenerator di dalam row lock.
 *
 * @property int $id
 * @property int $document_sequence_id
 * @property string $scope_key
 * @property int $last_number
 * @property Carbon|null $updated_at
 */
class DocumentSequenceCounter extends Model
{
    public const CREATED_AT = null;

    protected $table = 'document_sequence_counters';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_number' => 'integer'];
    }
}
