<?php

namespace App\Domain\Employee\Models;

use App\Domain\Attendance\Models\ShiftPattern;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Enums\Gender;
use App\Domain\Employee\Support\NikCipher;
use App\Domain\Reference\Models\ReferenceItem;
use App\Domain\Region\Models\Village;
use App\Models\User;
use App\Support\Authorization\ScopedRouteBinding;
use App\Support\Time\DisplayTime;
use Carbon\Carbon;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pegawai (04 §2.9, FR-025, BR-050). Route `{employee}` ber-scope data (06 §5): pegawai di luar
 * scope pengguna → 404, bukan 403 (SEC-033).
 *
 * `nik_enc`/`nik_hash` disembunyikan dari serialisasi; NIK hanya keluar termasking
 * (`maskedNik()`) atau lewat RevealNik yang tercatat (SEC-010).
 *
 * @property int $id
 * @property string $employee_number
 * @property string $full_name
 * @property string|null $nik_enc
 * @property string|null $nik_hash
 * @property Gender $gender
 * @property Carbon|null $birth_date
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $village_code
 * @property string|null $photo_path
 * @property int $employment_status_id
 * @property Carbon $join_date
 * @property Carbon|null $exit_date
 * @property int|null $exit_reason_id
 * @property string|null $exit_note
 * @property EmployeeStatus $status
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read ReferenceItem|null $employmentStatus
 * @property-read Village|null $village
 * @property-read Assignment|null $primaryAssignment
 * @property-read User|null $user
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory, ScopedRouteBinding, SoftDeletes;

    /** Kolom data diri yang diaudit & dibandingkan saat ubah. NIK diaudit terpisah sebagai `nik` (→ `***`). */
    public const AUDITED = ['full_name', 'gender', 'birth_date', 'phone', 'email', 'address', 'village_code', 'employment_status_id', 'join_date'];

    /**
     * `employee_number`, `status`, `nik_*`, `created_by` sengaja TIDAK fillable (SEC-034): hanya
     * diisi Action lewat forceFill.
     */
    protected $fillable = [...self::AUDITED, 'photo_path'];

    protected $hidden = ['nik_enc', 'nik_hash'];

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'status' => EmployeeStatus::class,
            'birth_date' => 'date:Y-m-d',
            'join_date' => 'date:Y-m-d',
            'exit_date' => 'date:Y-m-d',
            'employment_status_id' => 'integer',
            'exit_reason_id' => 'integer',
        ];
    }

    public static function scopedEmployeeColumn(): string
    {
        return 'id';
    }

    protected static function newFactory(): EmployeeFactory
    {
        return EmployeeFactory::new();
    }

    /** @return BelongsTo<ReferenceItem, $this> */
    public function employmentStatus(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'employment_status_id');
    }

    /** @return BelongsTo<Village, $this> */
    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class, 'village_code', 'code');
    }

    /** @return HasMany<Assignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * Penugasan utama yang berlaku HARI INI (tanggal lokal), sama dengan definisi aktif di
     * DatabaseOrgStructure. Penugasan yang baru mulai besok (mutasi terjadwal) belum dihitung.
     *
     * @return HasOne<Assignment, $this>
     */
    public function primaryAssignment(): HasOne
    {
        $today = DisplayTime::toLocal(now())->toDateString();

        return $this->hasOne(Assignment::class)
            ->where('is_primary', true)
            ->where('start_date', '<=', $today)
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
            ->orderByDesc('start_date');
    }

    /**
     * Riwayat pola shift mingguan (T-035), termasuk pola terjadwal yang belum berlaku.
     *
     * @return HasMany<ShiftPattern, $this>
     */
    public function shiftPatterns(): HasMany
    {
        return $this->hasMany(ShiftPattern::class);
    }

    /**
     * Pola yang berlaku HARI INI (tanggal lokal): `effective_from` terbaru ≤ hari ini (BR-061).
     * Pola yang baru berlaku besok belum dihitung, sama seperti primaryAssignment.
     *
     * @return HasOne<ShiftPattern, $this>
     */
    public function currentShiftPattern(): HasOne
    {
        $today = DisplayTime::toLocal(now())->toDateString();

        return $this->hasOne(ShiftPattern::class)
            ->ofMany(['effective_from' => 'max'], fn (Builder $q) => $q->where('effective_from', '<=', $today));
    }

    /** @return HasOne<User, $this> */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === EmployeeStatus::Active;
    }

    /** NIK termasking (`3319********0001`) atau null bila tidak diisi. */
    public function maskedNik(): ?string
    {
        return $this->nik_enc === null ? null : NikCipher::mask(app(NikCipher::class)->decrypt($this->nik_enc));
    }
}
