<?php

namespace Tests\Fixtures\Listing;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model khusus test mesin listing: tabel `users` + relasi ke `sessions` untuk uji pencarian relasi.
 */
class ListingTestUser extends User
{
    protected $table = 'users';

    /**
     * @return HasMany<ListingTestSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(ListingTestSession::class, 'user_id');
    }
}
