<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Region\Models\Village;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Supplier = pihak tempat Pakis membeli (15 §4.5, FR-A11). Boleh menunjuk prinsipalnya (beli langsung
 * dari pabrik = satu prinsipal + satu supplier). Nomor rekening SEC-108: hanya tersimpan terenkripsi
 * (`bank_account_no_enc`, cast `encrypted`), disembunyikan dari array/JSON, ditampilkan termasking;
 * nomor lengkap hanya lewat RevealSupplierAccount (tercatat `sensitive_data_viewed`).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int|null $principal_id
 * @property string|null $tax_id
 * @property string|null $contact_name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $village_code
 * @property int $payment_term_days
 * @property string|null $bank_name
 * @property string|null $bank_account_no_enc
 * @property string|null $bank_account_name
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Principal|null $principal
 * @property-read Village|null $village
 */
class Supplier extends Model
{
    use SoftDeletes;

    /** Kolom diaudit. Rekening TIDAK termasuk: perubahannya dicatat sebagai penanda "diubah" saja. */
    public const AUDITED = [
        'code', 'name', 'principal_id', 'tax_id', 'contact_name', 'phone', 'email', 'address', 'village_code',
        'payment_term_days', 'bank_name', 'bank_account_name', 'is_active',
    ];

    protected $fillable = [];

    /** Pengaman kedua: ciphertext pun tidak ikut serialisasi (Inertia/JSON/log). */
    protected $hidden = ['bank_account_no_enc'];

    protected function casts(): array
    {
        return [
            'principal_id' => 'integer',
            'payment_term_days' => 'integer',
            'bank_account_no_enc' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Principal, $this> */
    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class)->withTrashed();
    }

    /** @return BelongsTo<Village, $this> */
    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class, 'village_code', 'code');
    }

    /** @return HasMany<SupplierProduct, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function hasBankAccount(): bool
    {
        return $this->bank_account_no_enc !== null && $this->bank_account_no_enc !== '';
    }

    /**
     * `••••1234` (SEC-108): empat digit akhir saja, panjang titik tetap agar panjang nomor asli tidak
     * terbaca. Nomor ≤ 4 digit seluruhnya titik.
     */
    public function maskedAccount(): ?string
    {
        if (! $this->hasBankAccount()) {
            return null;
        }
        $digits = (string) $this->bank_account_no_enc;

        return strlen($digits) <= 4 ? '••••' : '••••'.substr($digits, -4);
    }

    /** @return array<string, mixed> */
    public function audited(): array
    {
        return $this->only(self::AUDITED);
    }
}
