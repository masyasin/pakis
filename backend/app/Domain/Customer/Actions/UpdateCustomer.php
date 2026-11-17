<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Enums\VerificationStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerVerification;
use App\Domain\Customer\Support\DuplicateGuard;
use App\Domain\Customer\Support\OfficeCallDrafts;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Upload\SecureUpload;
use App\Support\Upload\UploadTarget;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ubah profil kios (15 §5 `admin.customers.update`, T-073). Hanya kolom Customer::PROFILE dan
 * ketentuan dagang Customer::TRADE_TERMS (tempo panen, kelayakan pembeli terbatas + dokumennya,
 * T-074): kode, area/sales (lewat ReassignCustomers), status, limit, verifikasi, dan foto toko
 * tidak pernah diubah di sini (masing-masing lewat jalurnya sendiri, sebagian lewat approval).
 *
 * HP pemilik (BR-126, BR-166(4), PA-47): boleh diubah hanya selama kios `prospect` dan HP belum
 * terverifikasi. Setelah itu wajib jalur ganti HP + approval (T-074) → 409 STATE_CONFLICT.
 *
 * T-074: HP baru dicek BR-166(3) + HP ganda; nama/titik/desa yang berubah dicek duplikat (BR-166(2)).
 * HP prospek yang berubah saat verifikasi aktivasi masih menunggu membatalkan permintaan itu
 * (BR-153(10)): bukti OTP/telepon kantor hanya berlaku untuk nomor lama. Draf telepon kantor ikut dibuang.
 */
final class UpdateCustomer
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DuplicateGuard $duplicates,
        private readonly ApprovalEngine $approvals,
        private readonly OfficeCallDrafts $drafts,
        private readonly SecureUpload $uploads,
    ) {}

    /**
     * @param  array<string, mixed>  $data  sudah divalidasi CustomerRequest
     * @param  UploadedFile|null  $restrictedDocument  dokumen kelayakan pembeli terbatas baru (SEC-109)
     */
    public function handle(Customer $customer, array $data, ?int $userId, ?UploadedFile $restrictedDocument = null): Customer
    {
        // Berkas diperiksa & disimpan dulu: isi berkas yang ditolak (SEC-109) tidak mengubah data apa pun.
        $stored = $restrictedDocument !== null
            ? $this->uploads->store($restrictedDocument, UploadTarget::privateDocument('customer-documents', 'restricted_buyer_document'))
            : null;

        try {
            [$saved, $oldPath] = DB::transaction(fn () => $this->save($customer, $data, $userId, $stored?->path));
        } catch (Throwable $e) {
            $this->uploads->delete(CustomerVerification::DISK, $stored?->path);

            throw $e;
        }
        if ($oldPath !== null) {
            $this->uploads->delete(CustomerVerification::DISK, $oldPath);
        }

        return $saved;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Customer, 1: string|null} kios tersimpan + path dokumen lama yang sudah diganti
     */
    private function save(Customer $customer, array $data, ?int $userId, ?string $documentPath): array
    {
        $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
        $before = $locked->audited();
        $oldDocument = $locked->restricted_buyer_document_path;

        $locked->fill(array_intersect_key($data, array_flip([...Customer::PROFILE, ...Customer::TRADE_TERMS])));
        self::normalizeTradeTerms($locked, $documentPath);

        if ($locked->isDirty('owner_phone') && ! self::phoneEditable($locked)) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, publicMessage: 'HP pemilik kios ini sudah terverifikasi atau kiosnya bukan prospek lagi. Ganti HP lewat menu Ganti HP agar diverifikasi ulang.');
        }

        if (! $locked->isDirty()) {
            return [$locked, null];
        }

        $override = $locked->isDirty(['owner_phone', 'store_name', 'latitude', 'longitude', 'village_code'])
            ? $this->duplicates->check([...$locked->only(Customer::PROFILE), ...array_intersect_key($data, array_flip(DuplicateGuard::CONFIRM_KEYS))], $locked->id, phoneChanged: $locked->isDirty('owner_phone'))
            : null;
        $phoneChanged = $locked->isDirty('owner_phone');

        $locked->updated_by = $userId;
        $locked->save();

        $after = $locked->audited();
        if ($documentPath !== null) {
            // Path berkas tidak diaudit (nama acak); cukup tanda bahwa dokumen diganti.
            $after['restricted_buyer_document_replaced'] = true;
        }
        $this->audit->record('customer.updated', $locked, $before, $after);
        $this->duplicates->recordOverride($locked, $override, $data);

        if ($phoneChanged) {
            $this->withdrawPendingActivation($locked);
        }

        return [$locked, $documentPath !== null ? $oldDocument : null];
    }

    /**
     * Tempo panen & kelayakan pembeli terbatas (BR-112, T-074): tempo panen mati → batas hari dikosongkan;
     * layak pembeli terbatas wajib punya dokumen (baru atau yang sudah ada).
     */
    private static function normalizeTradeTerms(Customer $customer, ?string $documentPath): void
    {
        if ($documentPath !== null) {
            $customer->restricted_buyer_document_path = $documentPath;
        }
        if (! $customer->harvest_term_allowed) {
            $customer->harvest_term_max_days = null;
        }
        if ($customer->restricted_buyer_eligible && $customer->restricted_buyer_document_path === null) {
            $message = 'Unggah dokumen kelayakan pembeli pestisida terbatas.';
            throw new ApiError(ErrorCode::VALIDATION_FAILED, ['restricted_buyer_document' => $message], $message);
        }
    }

    private function withdrawPendingActivation(Customer $customer): void
    {
        $this->drafts->discard($customer->id);
        $pending = SubmitVerification::pending($customer->id);
        if ($pending === null) {
            return;
        }
        $this->approvals->withdraw($pending);
        $pending->forceFill(['status' => VerificationStatus::Cancelled])->save();
        $this->audit->record('customer.verification_withdrawn', $customer, null, [
            'verification_id' => $pending->id,
            'reason' => 'owner_phone_changed',
        ]);
    }

    public static function phoneEditable(Customer $customer): bool
    {
        return $customer->status === CustomerStatus::Prospect && $customer->owner_phone_verified_at === null;
    }
}
