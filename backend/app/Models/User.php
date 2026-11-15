<?php

namespace App\Models;

use App\Domain\Device\Models\Device;
use App\Domain\Employee\Models\Employee;
use App\Support\Authorization\ScopedRouteBinding;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Akun login (04 §2.2). Login dengan `username` atau `email` (BR-010); `username` selalu huruf kecil.
 * Route `{user}` ber-scope data lewat pegawai terkait (06 §5, keputusan Mas 15 Nov 2026): akun di
 * luar scope → 404; akun tanpa pegawai hanya terlihat pemegang scope `all`.
 *
 * @property int $id
 * @property int|null $employee_id
 * @property string $name
 * @property string $username
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property string|null $phone
 * @property string|null $avatar_path
 * @property string $password
 * @property bool $must_change_password
 * @property Carbon|null $password_changed_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property bool $is_active
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property string|null $telegram_chat_id
 * @property Carbon|null $telegram_linked_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'username', 'email', 'phone', 'password', 'employee_id', 'is_active', 'locked_until', 'must_change_password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, ScopedRouteBinding, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Kosong = "ingat saya" mati (SEC-005). SessionGuard melewati set/cycle remember token
     * bila nama kolomnya kosong, jadi logout tidak menulis ke kolom yang sudah dihapus.
     *
     * @var string
     */
    protected $rememberTokenName = '';

    /**
     * Sama dengan default kolom DB, agar model yang baru dibuat (belum di-refresh) tidak bernilai
     * null pada kolom yang dicek middleware akses.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'must_change_password' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'telegram_linked_at' => 'datetime',
        ];
    }

    /**
     * Username & email disimpan huruf kecil agar pencocokan login tidak bergantung kapitalisasi.
     *
     * @return Attribute<string, string>
     */
    protected function username(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)));
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null || trim($value) === '' ? null : mb_strtolower(trim($value)));
    }

    public static function scopedEmployeeColumn(): string
    {
        return 'employee_id';
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Pegawai terkait (T-024). Termasuk yang terhapus agar nama tetap tampil di riwayat akun.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /**
     * Perangkat Pakis Pro milik akun (04 §2.12, BR-040).
     *
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
