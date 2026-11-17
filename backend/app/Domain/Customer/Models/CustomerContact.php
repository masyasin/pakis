<?php

namespace App\Domain\Customer\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kontak kios selain pemilik (15 §4.6, T-074): penjaga toko, bagian bayar. Tidak dipakai untuk
 * konfirmasi saldo/kwitansi (itu selalu `customers.owner_phone`, BR-219), jadi HP-nya tidak dicek
 * BR-166(3). Ikut pindah ke kios tujuan saat kios digabung (BR-166(5)).
 *
 * @property int $id
 * @property int $customer_id
 * @property string $name
 * @property string|null $role
 * @property string|null $phone
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 */
class CustomerContact extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['customer_id' => 'integer'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return array{name: string, role: string|null, phone: string|null} */
    public function audited(): array
    {
        return ['name' => $this->name, 'role' => $this->role, 'phone' => $this->phone];
    }
}
