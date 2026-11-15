<?php

namespace App\Domain\Organization\Support;

use Illuminate\Support\Facades\DB;

/**
 * Pencegah siklus untuk tabel ber-induk (departemen, atasan jabatan). Menelusuri rantai induk dari
 * calon induk ke atas; bila melewati baris yang sedang diubah, memasang induk itu membuat lingkaran
 * (A → B → A). Rantai yang sudah rusak (data lama melingkar) dihentikan pengaman jumlah langkah.
 * Nama tabel & kolom selalu dari kode (konstanta pemanggil), bukan dari input.
 */
final class HierarchyGuard
{
    /** Kedalaman struktur yang wajar untuk ±150 pegawai; jauh di atas kebutuhan nyata. */
    private const MAX_DEPTH = 50;

    public static function wouldCycle(string $table, string $parentColumn, int $id, int $parentId): bool
    {
        $current = $parentId;

        for ($step = 0; $step < self::MAX_DEPTH; $step++) {
            if ($current === $id) {
                return true;
            }

            $next = DB::table($table)->where('id', $current)->value($parentColumn);
            if ($next === null) {
                return false;
            }
            $current = (int) $next;
        }

        // Rantai lebih dalam dari batas = kemungkinan besar data melingkar: tolak demi aman.
        return true;
    }
}
