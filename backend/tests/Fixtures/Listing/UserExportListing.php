<?php

namespace Tests\Fixtures\Listing;

use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\ListingSpec;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listing uji yang bisa diekspor. "Scope" disimulasikan: baris bernama HIDDEN_NAME tidak pernah
 * terlihat, baik di tampilan maupun di berkas ekspor.
 */
class UserExportListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return (new UserTestListing)->spec()->exportable();
    }

    public function exportQuery(User $user): Builder
    {
        return ListingTestUser::query()->where('name', '!=', UserTestListing::HIDDEN_NAME);
    }

    /**
     * @return ExportColumns<ListingTestUser>
     */
    public function exportColumns(): ExportColumns
    {
        return ExportColumns::for(ListingTestUser::class)
            ->add('Nama', fn (ListingTestUser $u) => $u->name)
            ->add('Email', fn (ListingTestUser $u) => $u->email)
            ->add('ID', fn (ListingTestUser $u) => $u->id);
    }
}
