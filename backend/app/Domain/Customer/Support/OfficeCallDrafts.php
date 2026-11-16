<?php

namespace App\Domain\Customer\Support;

use App\Domain\Customer\Models\CustomerVerification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Pengajuan verifikasi yang MENUNGGU telepon kantor (BR-165b, PA-49). Sales mengirim foto + titik
 * + limit lebih dulu; baris `customer_verifications` baru dibuat saat pengguna lain mencatat
 * teleponnya, karena CHECK `cv_method_chk` mewajibkan catatan telepon untuk metode `office_call`
 * dan CHECK itu tidak dilonggarkan.
 *
 * Penyimpanan sementara: foto langsung di disk privat (lokasi akhirnya), metadata di cache tanpa
 * kedaluwarsa (satu draf per kios; pengajuan baru menggantikan yang lama dan menghapus fotonya).
 * Tidak memakai TTL karena tidak ada setting batas tunggu (tidak boleh angka bisnis hardcode);
 * bila cache dibersihkan/terusir, draf hilang dan sales cukup mengirim ulang (PA-49).
 *
 * @phpstan-type Draft array{photo_path: string, latitude: string, longitude: string, requested_credit_limit: string, submitted_by: int, phone: string, submitted_at: string}
 */
final class OfficeCallDrafts
{
    /** @return Draft|null */
    public function get(int $customerId): ?array
    {
        $draft = Cache::get(self::key($customerId));
        if (! is_array($draft) || ! isset($draft['photo_path'], $draft['submitted_by'], $draft['phone'])) {
            return null;
        }

        // Dibentuk ulang per kunci: isi cache bukan sumber tepercaya untuk tipe.
        return [
            'photo_path' => (string) $draft['photo_path'],
            'latitude' => (string) ($draft['latitude'] ?? ''),
            'longitude' => (string) ($draft['longitude'] ?? ''),
            'requested_credit_limit' => (string) ($draft['requested_credit_limit'] ?? '0'),
            'submitted_by' => (int) $draft['submitted_by'],
            'phone' => (string) $draft['phone'],
            'submitted_at' => (string) ($draft['submitted_at'] ?? now()->toIso8601String()),
        ];
    }

    /** @param  Draft  $draft */
    public function put(int $customerId, array $draft): void
    {
        $this->discard($customerId);
        Cache::forever(self::key($customerId), $draft);
    }

    /** Hapus draf + fotonya (draf diganti/dibatalkan). */
    public function discard(int $customerId): void
    {
        $old = $this->get($customerId);
        if ($old !== null) {
            Storage::disk(CustomerVerification::DISK)->delete($old['photo_path']);
        }
        Cache::forget(self::key($customerId));
    }

    /** Draf sudah menjadi baris verifikasi: lupakan metadata, foto tetap dipakai baris itu. */
    public function forget(int $customerId): void
    {
        Cache::forget(self::key($customerId));
    }

    private static function key(int $customerId): string
    {
        return 'customer-office-call-draft:'.$customerId;
    }
}
