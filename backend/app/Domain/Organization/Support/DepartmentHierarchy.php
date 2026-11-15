<?php

namespace App\Domain\Organization\Support;

/** Pencegah siklus induk departemen (T-022). */
final class DepartmentHierarchy
{
    /** Apakah menjadikan `$parentId` sebagai induk `$departmentId` membuat siklus? */
    public static function wouldCycle(int $departmentId, int $parentId): bool
    {
        return HierarchyGuard::wouldCycle('departments', 'parent_id', $departmentId, $parentId);
    }
}
