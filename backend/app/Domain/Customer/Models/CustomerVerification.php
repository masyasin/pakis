<?php

namespace App\Domain\Customer\Models;

use App\Domain\Access\DataScope;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Customer\Enums\VerificationMethod;
use App\Domain\Customer\Enums\VerificationStatus;
use App\Domain\Customer\Enums\VerificationType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengajuan verifikasi kios (15 §4.6, BR-165/166(4), T-074). Satu baris = bukti nomor sudah lengkap
 * (OTP cocok atau catatan telepon kantor), foto + titik, dan limit yang diminta. Dokumen approval-nya
 * adalah subkelas per jenis (CustomerActivation; CustomerPhoneChange di T-074 bagian 2) di atas tabel
 * yang sama (PA-49).
 *
 * Foto di disk privat; ditampilkan lewat URL bertanda tangan (bagian 2, pola selfie T-038).
 *
 * @property int $id
 * @property int $customer_id
 * @property VerificationType $type
 * @property VerificationMethod $method
 * @property string $phone_target
 * @property string|null $phone_previous
 * @property Carbon|null $otp_verified_at
 * @property string|null $office_call_note
 * @property string|null $photo_path
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $requested_credit_limit
 * @property VerificationStatus $status
 * @property int|null $approval_request_id
 * @property int $submitted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 * @property-read User|null $submitter
 * @property-read ApprovalRequest|null $approvalRequest
 */
class CustomerVerification extends Model
{
    /** Disk foto toko & dokumen kios: privat (SEC-108/109). */
    public const DISK = 'local';

    protected $table = 'customer_verifications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => VerificationType::class,
            'method' => VerificationMethod::class,
            'status' => VerificationStatus::class,
            'otp_verified_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'requested_credit_limit' => 'decimal:2',
            'customer_id' => 'integer',
            'approval_request_id' => 'integer',
            'submitted_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<ApprovalRequest, $this> */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    /**
     * Route binding ber-scope (SEC-033): verifikasi kios di luar scope Pelanggan = 404.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $user = request()->user();
        if (! $user instanceof User) {
            return null;
        }

        $query = $this->resolveRouteBindingQuery($this->newQuery(), $value, $field);
        app(DataScope::class)->forCustomer($query, $user, $this->qualifyColumn('customer_id'));

        return $query->first();
    }
}
