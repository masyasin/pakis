<?php

namespace App\Domain\Region\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Master wilayah (04 §2.7). Kunci = kode bertitik, diisi `regions:import` (bukan dari layar).
 *
 * @property string $code
 * @property string $district_code
 * @property string $name
 */
class Village extends Model
{
    protected $table = 'villages';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['code', 'district_code', 'name'];

    /**
     * @return BelongsTo<District, $this>
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_code', 'code');
    }
}
