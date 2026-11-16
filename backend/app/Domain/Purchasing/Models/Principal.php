<?php

namespace App\Domain\Purchasing\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Prinsipal = produsen/pemilik merek (15 §4.5, FR-A11). Dipakai produk (merek), promo dari prinsipal
 * (Paket B), dan retur ke prinsipal. Tidak dihapus dari layar: dinonaktifkan agar riwayat utuh.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $contact_name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $website
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Principal extends Model
{
    use SoftDeletes;

    public const AUDITED = ['code', 'name', 'contact_name', 'phone', 'email', 'address', 'website', 'is_active'];

    protected $fillable = [...self::AUDITED, 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Supplier, $this> */
    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }
}
