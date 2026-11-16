<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Enums\RegistrationStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductRegistration;
use App\Domain\Catalog\Queries\ActiveRegistration;
use App\Support\Upload\SecureUpload;
use App\Support\Upload\StoredUpload;
use App\Support\Upload\UploadTarget;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Tambah/ubah izin edar (FR-A10, BR-152, SEC-109). Perpanjangan = izin BARU; izin lama tidak
 * diubah agar riwayat (nomor & berkas lama) tetap utuh. Karena itu hanya izin `active` yang boleh
 * diubah (koreksi salah ketik). Izin dengan `valid_until` sebelum hari ini langsung `expired`.
 * Berkas (PDF/JPG/PNG/WebP) lewat komponen unggahan bersama ke disk privat.
 */
final class SaveRegistration
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecureUpload $uploads,
    ) {}

    /** @param  array<string, mixed>  $data  sudah divalidasi ProductRegistrationRequest */
    public function handle(Product $product, ?ProductRegistration $registration, array $data, ?UploadedFile $document, ?int $userId): ProductRegistration
    {
        // Cek awal (sebelum unggah berkas) agar penolakan tidak meninggalkan berkas; dicek ulang di bawah kunci.
        if ($registration !== null && $registration->status !== RegistrationStatus::Active) {
            throw self::notEditable();
        }

        $stored = $document !== null
            ? $this->uploads->store($document, UploadTarget::privateDocument('product-registrations', 'document'))
            : null;

        try {
            [$saved, $oldDocument] = DB::transaction(fn () => $this->save($product, $registration, $data, $stored, $userId));
        } catch (Throwable $e) {
            if ($stored !== null) {
                $this->uploads->delete($stored->disk, $stored->path);
            }
            if ($e instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['registration_no' => 'Nomor izin ini sudah terdaftar di produk ini.']);
            }

            throw $e;
        }

        // Berkas lama dihapus SETELAH commit: bila transaksi gagal, berkas lama tetap utuh.
        if ($oldDocument !== null) {
            $this->uploads->delete(ProductRegistration::DOCUMENT_DISK, $oldDocument);
        }

        return $saved;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ProductRegistration, 1: string|null}
     */
    private function save(Product $product, ?ProductRegistration $registration, array $data, ?StoredUpload $stored, ?int $userId): array
    {
        $validUntil = (string) $data['valid_until'];
        $values = [
            'registration_no' => strtoupper(trim((string) $data['registration_no'])),
            'holder_name' => self::nullable($data['holder_name'] ?? null),
            'issued_at' => self::nullable($data['issued_at'] ?? null),
            'valid_until' => $validUntil,
            'note' => self::nullable($data['note'] ?? null),
            'status' => $validUntil < ActiveRegistration::localDate() ? RegistrationStatus::Expired : RegistrationStatus::Active,
        ];

        if ($registration === null) {
            $created = (new ProductRegistration)->forceFill([
                ...$values, 'product_id' => $product->id, 'document_path' => $stored?->path,
                'created_by' => $userId, 'updated_by' => $userId,
            ]);
            $created->save();
            $this->audit->record('product_registration.created', $created, null, [...$created->audited(), 'document' => $stored !== null ? 'diunggah' : null]);

            return [$created, null];
        }

        $locked = ProductRegistration::query()->lockForUpdate()->findOrFail($registration->id);
        // Periksa ulang SETELAH dikunci: pencabutan/job expire yang berjalan bersamaan sesudah cek awal
        // tidak boleh "dihidupkan" lagi oleh status yang dihitung ulang dari tanggal di bawah.
        if ($locked->status !== RegistrationStatus::Active) {
            throw self::notEditable();
        }
        $before = $locked->audited();
        $oldDocument = $stored !== null ? $locked->document_path : null;
        $locked->forceFill([...$values, 'updated_by' => $userId]);
        if ($stored !== null) {
            $locked->document_path = $stored->path;
        }
        if (! $locked->isDirty()) {
            return [$locked, null];
        }
        $locked->save();
        $this->audit->record('product_registration.updated', $locked, $before, [...$locked->audited(), 'document' => $stored !== null ? 'diganti' : null]);

        return [$locked, $oldDocument];
    }

    private static function notEditable(): ValidationException
    {
        return ValidationException::withMessages([
            'registration' => 'Izin yang sudah habis atau dicabut tidak bisa diubah. Untuk perpanjangan, tambahkan izin baru.',
        ]);
    }

    private static function nullable(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
