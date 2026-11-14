<?php

namespace App\Domain\Region\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Master wilayah (04 §2.7). Kunci = kode bertitik, diisi `regions:import` (bukan dari layar).
 *
 * @property string $code
 * @property string $regency_code
 * @property string $name
 */
class District extends Model
{
    protected $table = 'districts';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['code', 'regency_code', 'name'];

    /**
     * @return BelongsTo<Regency, $this>
     */
    public function regency(): BelongsTo
    {
        return $this->belongsTo(Regency::class, 'regency_code', 'code');
    }
}
