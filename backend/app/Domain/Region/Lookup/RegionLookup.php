<?php

namespace App\Domain\Region\Lookup;

use App\Domain\Region\Enums\RegionLevel;
use App\Domain\Region\Import\RegionCode;
use App\Domain\Region\Models\District;
use App\Domain\Region\Models\Province;
use App\Domain\Region\Models\Regency;
use App\Domain\Region\Models\Village;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Lookup `regions?level=…&parent=…` (05 §4.11): pilihan wilayah berjenjang. Data publik dan tidak
 * ber-scope, jadi boleh untuk setiap pengguna login (form lokasi, alamat pegawai, filter).
 */
final class RegionLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'level' => ['required', Rule::enum(RegionLevel::class)],
            'parent' => ['nullable', 'string', 'regex:'.RegionCode::PATTERN],
        ];
    }

    public function keyRules(): array
    {
        return ['string', 'regex:'.RegionCode::PATTERN];
    }

    public function query(array $params): Builder
    {
        $level = RegionLevel::from((string) $params['level']);
        $query = match ($level) {
            RegionLevel::Province => Province::query(),
            RegionLevel::Regency => Regency::query(),
            RegionLevel::District => District::query(),
            RegionLevel::Village => Village::query(),
        };

        $column = $level->parentColumn();
        if ($column !== null && ! empty($params['parent'])) {
            $query->where($column, (string) $params['parent']);
        }

        return $query;
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->contains('name')->prefix('code');
    }

    public function order(Builder $query): void
    {
        $query->orderBy('name')->orderBy('code');
    }

    public function option(Model $model): array
    {
        if (! $model instanceof Province && ! $model instanceof Regency && ! $model instanceof District && ! $model instanceof Village) {
            throw new LogicException('Lookup wilayah menerima model selain wilayah.');
        }

        return ['value' => $model->code, 'label' => $model->name, 'hint' => $model->code];
    }
}
