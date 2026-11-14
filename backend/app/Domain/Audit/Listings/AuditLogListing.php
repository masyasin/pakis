<?php

namespace App\Domain\Audit\Listings;

use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Time\DisplayTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar audit log (katalog 07 §3.4: `audit_logs`). Default 7 hari terakhir agar tabel besar tidak
 * dipindai penuh setiap halaman dibuka.
 */
final class AuditLogListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        $events = AuditLog::query()->distinct()->orderBy('event')->limit(200)->pluck('event');
        $modules = $events->map(fn (string $e) => explode('.', $e)[0])->unique()->values();

        return ListingSpec::make('audit_logs')
            ->searchPlaceholder('Cari event, objek (tipe#id), IP, atau nama pelaku')
            ->search(TextSearch::make()
                ->prefix('event')
                ->prefix('ip')
                ->relation('user', 'name')
                ->using(function (Builder $q, string $word): void {
                    // `employee#12` → objek tertentu; tipe dicocokkan di akhir nama kelas/alias morph.
                    if (preg_match('/^([a-z_]+)#(\d+)$/i', $word, $m) === 1) {
                        $q->orWhere(fn (Builder $sub) => $sub
                            ->where('auditable_type', 'like', '%'.TextSearch::escapeLike($m[1]))
                            ->where('auditable_id', (int) $m[2]));
                    }
                }))
            ->filters([
                RelationFilter::make('user_id', 'Pelaku')->source('users')
                    ->validIds(fn () => User::query()),
                MultiSelectFilter::make('event', 'Event')
                    ->options($events->mapWithKeys(fn (string $e) => [$e => $e])->all()),
                MultiSelectFilter::make('module', 'Modul')
                    ->options($modules->mapWithKeys(fn (string $m) => [$m => $m])->all())
                    ->using(fn (Builder $q, array $values) => $q->where(function (Builder $sub) use ($values) {
                        foreach ($values as $module) {
                            $sub->orWhere('event', 'like', TextSearch::escapeLike($module).'.%');
                        }
                    })),
                DateRangeFilter::make('occurred_at', 'Waktu')->onTimestamp()
                    ->default(fn () => [
                        'from' => DisplayTime::toLocal(now())->subDays(6)->toDateString(),
                        'to' => DisplayTime::toLocal(now())->toDateString(),
                    ]),
            ])
            ->sorts([
                Sort::make('occurred_at', 'Waktu'),
            ])
            ->defaultSort('-occurred_at')
            ->exportable();
    }

    public function exportQuery(User $user): Builder
    {
        // TODO(T-019): scope data bila kelak ada role non-global yang boleh melihat audit.
        return AuditLog::query()->with('user:id,name');
    }

    /**
     * @return ExportColumns<AuditLog>
     */
    public function exportColumns(): ExportColumns
    {
        return ExportColumns::for(AuditLog::class)
            ->add('Waktu', fn (AuditLog $l) => $l->occurred_at)
            ->add('Pelaku', fn (AuditLog $l) => $l->user->name ?? $l->actor_label ?? 'sistem')
            ->add('Event', fn (AuditLog $l) => $l->event)
            ->add('Objek', fn (AuditLog $l) => $l->auditable_type ? class_basename($l->auditable_type).'#'.$l->auditable_id : null)
            ->add('Nilai lama', fn (AuditLog $l) => $l->old_values)
            ->add('Nilai baru', fn (AuditLog $l) => $l->new_values)
            ->add('IP', fn (AuditLog $l) => $l->ip)
            ->add('Request ID', fn (AuditLog $l) => $l->request_id);
    }
}
