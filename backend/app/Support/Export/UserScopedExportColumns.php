<?php

namespace App\Support\Export;

use App\Models\User;

/**
 * Listing ekspor yang kolomnya bergantung pada izin pembuat ekspor (SEC-107, T-087, PA-72): kolom
 * biaya/HPP hanya untuk `stock.view_cost`. ExportRunner memakai `exportColumnsFor()` bila listing
 * mengimplementasikan antarmuka ini; listing lain tetap memakai `exportColumns()`.
 */
interface UserScopedExportColumns
{
    /**
     * @return ExportColumns<*>
     */
    public function exportColumnsFor(User $user): ExportColumns;
}
