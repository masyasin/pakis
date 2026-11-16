<?php

namespace App\Domain\Customer\Duplicates;

use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\DecimalString;
use App\Support\Geo\Haversine;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kandidat duplikat kios (BR-166(2), SEC-105, TA-03), tanpa dependency baru:
 * - HP sama dengan kios mana pun yang belum digabung;
 * - nama toko mirip (huruf kecil, tanda baca & kata umum "toko", "tani", "ud", "cv" dibuang, lalu
 *   `similar_text` ≥ `customer.duplicate_name_similarity`) DAN (berjarak Haversine ≤
 *   `customer.duplicate_radius_m` ATAU di desa yang sama).
 *
 * Pencarian lintas SELURUH kios, bukan hanya scope pengguna: kios ganda di area sales lain justru
 * yang paling perlu ketahuan. Yang dibuka ke pengguna diatur controller (kios di luar scope hanya
 * kode & nama, PA-52). Kios `merged` tidak pernah jadi kandidat.
 */
final class DuplicateFinder
{
    /** Kata umum nama kios yang tidak membedakan (BR-166(2)). */
    public const STOP_WORDS = ['toko', 'tani', 'ud', 'cv'];

    /** Batas kandidat yang dikembalikan: cukup untuk dialog konfirmasi, tidak memuat seluruh tabel. */
    private const LIMIT = 10;

    /**
     * @return list<DuplicateCandidate> urut: HP sama dulu, lalu paling mirip, lalu paling dekat
     */
    public function find(string $storeName, ?string $phone, ?string $latitude, ?string $longitude, ?string $villageCode, ?int $exceptCustomerId = null): array
    {
        $name = self::normalizeName($storeName);
        $threshold = (float) DecimalString::normalize((string) setting('customer.duplicate_name_similarity'));
        $radius = (int) setting('customer.duplicate_radius_m');
        $hasPoint = $latitude !== null && $longitude !== null && is_numeric($latitude) && is_numeric($longitude);

        $query = Customer::query()
            ->where('status', '<>', CustomerStatus::Merged)
            ->when($exceptCustomerId !== null, fn (Builder $q) => $q->whereKeyNot($exceptCustomerId))
            ->where(function (Builder $w) use ($phone, $villageCode, $hasPoint, $latitude, $longitude, $radius) {
                $w->whereRaw('1 = 0');
                if ($phone !== null && $phone !== '') {
                    $w->orWhere('owner_phone', $phone);
                }
                if ($villageCode !== null && $villageCode !== '') {
                    $w->orWhere('village_code', $villageCode);
                }
                if ($hasPoint && $radius > 0) {
                    // Kotak pembatas di SQL dulu (murah), jarak pasti dihitung Haversine di PHP.
                    $lat = (float) $latitude;
                    $dLat = $radius / 111_320;
                    $dLon = $radius / (111_320 * max(0.01, cos(deg2rad($lat))));
                    $w->orWhere(fn (Builder $b) => $b
                        ->whereBetween('latitude', [$lat - $dLat, $lat + $dLat])
                        ->whereBetween('longitude', [(float) $longitude - $dLon, (float) $longitude + $dLon]));
                }
            })
            ->with(['salesArea:id,code,name', 'village:code,name'])
            ->limit(500);

        $candidates = [];
        foreach ($query->get() as $customer) {
            $reasons = [];
            if ($phone !== null && $phone !== '' && $customer->owner_phone === $phone) {
                $reasons[] = 'phone';
            }

            $similarity = self::similarity($name, self::normalizeName($customer->store_name));
            $distance = $hasPoint && $customer->latitude !== null && $customer->longitude !== null
                ? (int) round(Haversine::meters((float) $latitude, (float) $longitude, (float) $customer->latitude, (float) $customer->longitude))
                : null;

            if ($similarity >= $threshold) {
                if ($distance !== null && $radius > 0 && $distance <= $radius) {
                    $reasons[] = 'name_nearby';
                }
                if ($villageCode !== null && $villageCode !== '' && $customer->village_code === $villageCode) {
                    $reasons[] = 'name_same_village';
                }
            }

            if ($reasons !== []) {
                $candidates[] = new DuplicateCandidate($customer, $reasons, round($similarity, 2), $distance);
            }
        }

        usort($candidates, fn (DuplicateCandidate $a, DuplicateCandidate $b) => [
            in_array('phone', $b->reasons, true), $b->similarity, -($a->distanceM ?? PHP_INT_MAX),
        ] <=> [
            in_array('phone', $a->reasons, true), $a->similarity, -($b->distanceM ?? PHP_INT_MAX),
        ]);

        return array_slice($candidates, 0, self::LIMIT);
    }

    /** Huruf kecil, tanda baca → spasi, kata umum dibuang. Nama yang habis → nama huruf kecil asli. */
    public static function normalizeName(string $name): string
    {
        $lower = mb_strtolower(trim($name));
        $words = preg_split('/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = array_values(array_diff($words, self::STOP_WORDS));

        return $kept === [] ? implode(' ', $words) : implode(' ', $kept);
    }

    /** 0–1, `similar_text` persen terhadap rata-rata panjang (simetris). */
    public static function similarity(string $a, string $b): float
    {
        if ($a === '' && $b === '') {
            return 1.0;
        }
        similar_text($a, $b, $percent);

        return $percent / 100;
    }
}
