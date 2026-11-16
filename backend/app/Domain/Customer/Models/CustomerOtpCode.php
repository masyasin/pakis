<?php

namespace App\Domain\Customer\Models;

use App\Domain\Customer\Enums\VerificationType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Kode OTP kios (15 §4.6, SEC-105). `code_hash` = HMAC kode (PA-50); kode asli tidak pernah
 * disimpan. Hanya CustomerOtpService yang menulis tabel ini.
 *
 * @property int $id
 * @property int $customer_id
 * @property VerificationType $purpose
 * @property string $phone
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property int $attempts
 * @property Carbon|null $consumed_at
 * @property int $sent_by
 * @property Carbon|null $created_at
 */
class CustomerOtpCode extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'customer_otp_codes';

    protected $guarded = ['id'];

    /** Hash tidak pernah ikut serialisasi (respons/log). */
    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'purpose' => VerificationType::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
            'customer_id' => 'integer',
            'sent_by' => 'integer',
        ];
    }
}
