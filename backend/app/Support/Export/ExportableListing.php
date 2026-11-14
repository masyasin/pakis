<?php

namespace App\Support\Export;

use App\Models\User;
use App\Support\Listing\HasListing;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listing yang bisa diekspor (BR-005). Query dasar dibangun dari PENGGUNA, bukan dari request,
 * karena ekspor besar dijalankan ulang di job antrean tanpa sesi: scope data harus bisa
 * diterapkan lagi untuk pembuat ekspor.
 */
interface ExportableListing extends HasListing
{
    /**
     * Query dasar + scope data pengguna (DataScope, T-019), sama dengan yang dipakai tampilan.
     *
     * @return Builder<*>
     */
    public function exportQuery(User $user): Builder;

    /**
     * @return ExportColumns<*>
     */
    public function exportColumns(): ExportColumns;
}
