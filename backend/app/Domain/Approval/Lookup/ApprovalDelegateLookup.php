<?php

namespace App\Domain\Approval\Lookup;

use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `approval_delegates` (05 §4.11, T-067): pilihan penerima/pemberi delegasi di form delegasi.
 * Lookup `users` hanya untuk pemegang `audit.view`/`users.view`, padahal setiap calon penyetuju
 * (supervisor, kepala gudang, ...) boleh membuat delegasi miliknya (15 §5). Hanya akun aktif, label
 * nama + username (tanpa email/HP), tidak ber-scope data: delegasi lintas lokasi adalah hal biasa.
 */
final class ApprovalDelegateLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return true;
    }

    public function query(array $params): Builder
    {
        return User::query()->where('is_active', true);
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->contains('name')->prefix('username');
    }

    public function order(Builder $query): void
    {
        $query->orderBy('name')->orderBy('id');
    }

    public function option(Model $model): array
    {
        if (! $model instanceof User) {
            throw new LogicException('Lookup menerima model selain User.');
        }

        return ['value' => $model->id, 'label' => $model->name, 'hint' => $model->username];
    }
}
