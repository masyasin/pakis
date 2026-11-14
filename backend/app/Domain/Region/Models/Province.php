<?php

namespace App\Domain\Region\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Master wilayah (04 §2.7). Kunci = kode bertitik, diisi `regions:import` (bukan dari layar).
 *
 * @property string $code
 * @property string $name
 */
class Province extends Model
{
    protected $table = 'provinces';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['code', 'name'];
}
