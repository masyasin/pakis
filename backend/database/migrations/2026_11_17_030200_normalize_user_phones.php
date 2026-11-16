<?php

use App\Support\Phone\PhoneNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Addendum T-020 (T-073, BR-166(1)/(3)): `users.phone` lama disimpan seragam `62…` lewat normalizer
 * bersama, karena T-074 membandingkan HP kios dengan HP akun. Nilai yang tidak bisa dinormalisasi
 * DIBIARKAN (tidak ditebak) dan dilaporkan ke log berisi ID akun saja, tanpa nomornya (09 §6: data
 * pribadi tidak ditulis ke log). Admin memperbaikinya lewat form Pengguna.
 *
 * Lewat query builder (bukan model) agar tidak memicu audit/observer dan `updated_at` tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        $invalid = [];
        $changed = 0;

        DB::table('users')->whereNotNull('phone')->where('phone', '<>', '')
            ->select(['id', 'phone'])
            ->chunkById(500, function ($rows) use (&$invalid, &$changed) {
                foreach ($rows as $row) {
                    $normalized = PhoneNormalizer::normalize((string) $row->phone);

                    if ($normalized === null) {
                        $invalid[] = (int) $row->id;
                    } elseif ($normalized !== $row->phone) {
                        DB::table('users')->where('id', $row->id)->update(['phone' => $normalized]);
                        $changed++;
                    }
                }
            });

        if ($changed > 0 || $invalid !== []) {
            Log::warning('users.phone dinormalisasi ke format 62…', [
                'changed' => $changed,
                'invalid_count' => count($invalid),
                'invalid_user_ids' => $invalid,
            ]);
        }
    }

    /** Format lama tidak bisa dipulihkan (dan tidak perlu): `62…` sah untuk semua jalur. */
    public function down(): void {}
};
