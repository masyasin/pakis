<?php

namespace App\Domain\Organization\Support;

/** Pencegah siklus atasan jabatan (T-023): Sales → Supervisor → … tidak boleh kembali ke Sales. */
final class PositionHierarchy
{
    /** Apakah menjadikan `$parentId` sebagai atasan `$positionId` membuat siklus? */
    public static function wouldCycle(int $positionId, int $parentId): bool
    {
        return HierarchyGuard::wouldCycle('positions', 'parent_position_id', $positionId, $parentId);
    }
}
