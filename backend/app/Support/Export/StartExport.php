<?php

namespace App\Support\Export;

use App\Jobs\RunExport;
use App\Models\User;
use App\Support\Export\Events\ExportCreated;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\ListingResult;
use App\Support\Listing\ListingSpec;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Pintu semua route `admin.<modul>.export` (BR-005, FR-029):
 * - > export.max_rows → ditolak, minta persempit filter;
 * - ≤ export.sync_max_rows → berkas dibuat sekarang dan langsung diunduh;
 * - selebihnya → antrean, pengguna mengambilnya di halaman Unduhan.
 */
final class StartExport
{
    public function __construct(private readonly ExportRunner $runner) {}

    public function handle(Request $request, string $listingKey, User $user): Response
    {
        $listing = ExportRegistry::resolve($listingKey);
        $result = ListingQuery::apply($listing->exportQuery($user), $listing, $request);
        $total = $result->paginator()->total();

        if ($total > ExportRegistry::maxRows()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => sprintf(
                    'Terlalu banyak baris untuk diekspor (%s, maksimal %s). Persempit filter lalu coba lagi.',
                    number_format($total, 0, ',', '.'),
                    number_format(ExportRegistry::maxRows(), 0, ',', '.'),
                ),
            ]);

            return back();
        }

        $file = ExportFile::query()->create([
            'listing' => $listingKey,
            'params' => self::params($result, $listing->spec()),
            'status' => ExportStatus::Queued,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        ExportCreated::dispatch($file, $total);

        if ($total <= ExportRegistry::syncMaxRows()) {
            try {
                $this->runner->run($file);
            } catch (Throwable $e) {
                // Jangan tinggalkan status `queued` palsu; galat tetap dilaporkan ke log/handler.
                $file->forceFill(['status' => ExportStatus::Failed])->save();

                throw $e;
            }

            return Storage::disk(ExportRunner::DISK)->download((string) $file->path, $file->downloadName());
        }

        RunExport::dispatch($file->id);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Ekspor sedang diproses. Ambil berkasnya di menu Unduhan setelah siap.',
        ]);

        return back();
    }

    /**
     * Parameter yang SUDAH divalidasi untuk dijalankan ulang di job. Filter default yang dimatikan
     * pengguna disimpan sebagai '' agar job tidak menerapkan default itu lagi.
     *
     * @return array{q: string, sort: string, filter: array<string, mixed>}
     */
    private static function params(ListingResult $result, ListingSpec $spec): array
    {
        $state = $result->state();
        $filter = $state['filters'];

        foreach ($spec->filterList() as $key => $definition) {
            if (! array_key_exists($key, $filter) && $definition->defaultValue() !== null) {
                $filter[$key] = '';
            }
        }

        return ['q' => $state['q'], 'sort' => $state['sort'], 'filter' => $filter];
    }
}
