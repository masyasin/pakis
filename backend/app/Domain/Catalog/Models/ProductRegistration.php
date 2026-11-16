<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\RegistrationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Izin edar produk (FR-A10, BR-152). Satu produk boleh punya beberapa izin: perpanjangan = baris
 * baru, izin lama tidak diubah. Izin berlaku = `active` dengan `valid_until` terjauh
 * (`ActiveRegistration`). `valid_until` tanggal sipil lokal, berlaku sampai akhir hari itu.
 *
 * @property int $id
 * @property int $product_id
 * @property string $registration_no
 * @property string|null $holder_name
 * @property Carbon|null $issued_at
 * @property Carbon $valid_until
 * @property string|null $document_path
 * @property RegistrationStatus $status
 * @property string|null $note
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 */
class ProductRegistration extends Model
{
    /** Berkas izin selalu di disk privat; dibuka lewat signed URL berumur pendek (SEC-109). */
    public const DOCUMENT_DISK = 'local';

    public const AUDITED = ['product_id', 'registration_no', 'holder_name', 'issued_at', 'valid_until', 'status', 'note'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'issued_at' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'status' => RegistrationStatus::class,
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return array<string, mixed> */
    public function audited(): array
    {
        return [
            'product_id' => $this->product_id,
            'registration_no' => $this->registration_no,
            'holder_name' => $this->holder_name,
            'issued_at' => $this->issued_at?->toDateString(),
            'valid_until' => $this->valid_until->toDateString(),
            'status' => $this->status->value,
            'note' => $this->note,
        ];
    }
}
