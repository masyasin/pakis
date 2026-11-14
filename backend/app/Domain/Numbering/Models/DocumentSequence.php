<?php

namespace App\Domain\Numbering\Models;

use App\Domain\Numbering\Enums\ResetPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Definisi penomoran satu jenis dokumen (04 §2.5). Kode dipakai kode program, jadi tidak bisa
 * diubah dari layar; yang bisa diubah hanya pola & periode reset.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $pattern
 * @property ResetPeriod $reset_period
 * @property bool $is_system
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentSequence extends Model
{
    protected $table = 'document_sequences';

    protected $fillable = ['code', 'name', 'pattern', 'reset_period', 'is_system', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'reset_period' => ResetPeriod::class,
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return HasMany<DocumentSequenceCounter, $this>
     */
    public function counters(): HasMany
    {
        return $this->hasMany(DocumentSequenceCounter::class);
    }
}
