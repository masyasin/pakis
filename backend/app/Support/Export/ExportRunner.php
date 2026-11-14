<?php

namespace App\Support\Export;

use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\ListingResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Menulis berkas untuk satu ExportFile. Dipakai jalur sinkron (StartExport) DAN job antrean
 * (RunExport), jadi ekspor kecil & besar memakai kode yang sama persis (BR-005).
 */
final class ExportRunner
{
    public const DISK = 'local';

    public function __construct(private readonly XlsxExporter $exporter) {}

    /**
     * Bangun ulang listing dari parameter tersimpan + pembuat. Query lewat ListingQuery lagi,
     * sehingga validasi filter & scope berlaku sama seperti saat tampilan dibuka.
     */
    public static function rebuild(ExportFile $file, User $user): ListingResult
    {
        $listing = ExportRegistry::resolve($file->listing);
        $request = Request::create('/', 'GET', $file->params);

        return ListingQuery::apply($listing->exportQuery($user), $listing, $request);
    }

    public function run(ExportFile $file): void
    {
        $user = $file->creator ?? throw new RuntimeException("Pembuat ekspor #{$file->id} tidak ditemukan.");
        $listing = ExportRegistry::resolve($file->listing);

        $path = 'exports/'.Str::ulid().'.xlsx';
        $rows = $this->exporter->write(
            self::rebuild($file, $user)->query(),
            $listing->exportColumns(),
            Storage::disk(self::DISK)->path($path),
        );

        $file->forceFill([
            'status' => ExportStatus::Ready,
            'path' => $path,
            'row_count' => $rows,
            'expires_at' => now()->addDays(ExportRegistry::retentionDays()),
        ])->save();
    }
}
