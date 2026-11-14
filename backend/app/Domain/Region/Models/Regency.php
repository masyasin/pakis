<?php

namespace App\Domain\Region\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Master wilayah (04 §2.7). Kunci = kode bertitik, diisi `regions:import` (bukan dari layar).
 *
 * @property string $code
 * @property string $province_code
 * @property string $name
 */
class Regency extends Model
{
    protected $table = 'regencies';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['code', 'province_code', 'name'];

    /**
     * @return BelongsTo<Province, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_code', 'code');
    }
}
