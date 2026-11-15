<?php

namespace App\Domain\Organization\Support;

use Illuminate\Support\Facades\DB;

/**
 * Pencegah siklus induk departemen (T-022). Menelusuri rantai induk dari calon induk ke atas;
 * bila rantai itu melewati departemen yang sedang diubah, memasang induk tersebut membuat
 * lingkaran (A → B → A). Rantai yang sudah rusak (data lama melingkar) juga dihentikan oleh
 * pengaman jumlah langkah, sehingga penelusuran tidak pernah berputar tanpa akhir.
 */
final class DepartmentHierarchy
{
    /** Kedalaman struktur yang wajar untuk ±150 pegawai; jauh di atas kebutuhan nyata. */
    private const MAX_DEPTH = 50;

    /** Apakah menjadikan `$parentId` sebagai induk `$departmentId` membuat siklus? */
    public static function wouldCycle(int $departmentId, int $parentId): bool
    {
        $current = $parentId;

        for ($step = 0; $step < self::MAX_DEPTH; $step++) {
            if ($current === $departmentId) {
                return true;
            }

            $next = DB::table('departments')->where('id', $current)->value('parent_id');
            if ($next === null) {
                return false;
            }
            $current = (int) $next;
        }

        // Rantai lebih dalam dari batas = kemungkinan besar data melingkar: tolak demi aman.
        return true;
    }
}
