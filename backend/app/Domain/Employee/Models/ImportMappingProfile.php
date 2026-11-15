<?php

namespace App\Domain\Employee\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Profil pemetaan kolom (04 §2.10, BR-054): dipilih otomatis saat header berkas baru punya
 * `header_signature` yang sama. Tidak memuat data pegawai, jadi dipakai bersama semua pemegang
 * `employees.import`. "Terakhir dipakai" = `updated_at` (di-touch setiap dipakai).
 *
 * @property int $id
 * @property string $type
 * @property string $name
 * @property string $header_signature
 * @property array<string, string> $mapping judul kolom → field sistem
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 */
class ImportMappingProfile extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return ['mapping' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
