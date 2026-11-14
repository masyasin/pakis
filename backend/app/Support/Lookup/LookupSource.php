<?php

namespace App\Support\Lookup;

use App\Domain\Access\DataScope;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu sumber lookup `admin.lookup.<kunci>` (05 §4.11, BR-006): opsi untuk select/autocomplete.
 *
 * Kerangka (LookupController) yang menjamin batas: pencarian lewat TextSearch, maksimal 20 baris
 * dengan LIMIT di SQL, dan `scope()` selalu diterapkan. Sumber cukup menjelaskan query dasarnya.
 */
abstract class LookupSource
{
    /** Siapa yang boleh memakai sumber ini (izin, bukan scope). */
    abstract public function authorize(User $user): bool;

    /**
     * Query dasar: HANYA entri aktif (BR-006) + parameter khusus sumber (sudah divalidasi `rules()`).
     *
     * @param  array<string, mixed>  $params
     * @return Builder<covariant Model>
     */
    abstract public function query(array $params): Builder;

    /** Kolom yang dicari (prefix/contains), memakai aturan & escape yang sama dengan daftar. */
    abstract public function search(): TextSearch;

    /**
     * @param  Builder<covariant Model>  $query
     */
    abstract public function order(Builder $query): void;

    /**
     * @return array{value: int|string, label: string, hint?: string|null}
     */
    abstract public function option(Model $model): array;

    /**
     * Aturan validasi parameter khusus sumber (mis. `group` untuk referensi).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Aturan tiap nilai `ids[]` (kunci entri). Default ID bilangan bulat; sumber berkunci string
     * (mis. kode wilayah) menggantinya.
     *
     * @return list<mixed>
     */
    public function keyRules(): array
    {
        return ['integer', 'min:1'];
    }

    /**
     * Kolom pegawai pemilik data untuk sumber ber-scope (06 §5), mis. `employees.id`. Null = data
     * tidak terikat pegawai (referensi, wilayah, role, akun untuk filter audit).
     */
    public function scopeColumn(): ?string
    {
        return null;
    }

    /**
     * Batasi ke data dalam scope pemanggil (06 §5, BR-032). Sumber data pegawai cukup mengisi
     * `scopeColumn()`; sumber lokasi (T-021) menimpa method ini.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function scope(Builder $query, User $user): void
    {
        $column = $this->scopeColumn();

        if ($column !== null) {
            app(DataScope::class)->apply($query, $user, $column);
        }
    }
}
