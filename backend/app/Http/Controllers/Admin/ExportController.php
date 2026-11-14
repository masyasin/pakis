<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Export\ExportFile;
use App\Support\Export\ExportFileListing;
use App\Support\Export\ExportRunner;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Time\DisplayTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman Unduhan: berkas ekspor milik pengguna sendiri (05 §4.7, SEC-051).
 */
class ExportController extends Controller
{
    #[UsesListing(ExportFileListing::class)]
    public function index(Request $request): Response
    {
        $userId = $request->user()?->getAuthIdentifier();
        $minutes = (int) config('pakis.export.download_url_minutes');

        $result = ListingQuery::apply(ExportFile::query()->where('created_by', $userId), new ExportFileListing, $request)
            ->through(fn (ExportFile $file) => [
                'id' => $file->id,
                'listing' => $file->listing,
                'status' => $file->status->value,
                'status_label' => $file->status->label(),
                'row_count' => $file->row_count,
                'created_at' => $file->created_at ? DisplayTime::toLocal($file->created_at)->translatedFormat('j M Y H.i') : null,
                'expires_at' => $file->expires_at ? DisplayTime::toLocal($file->expires_at)->translatedFormat('j M Y H.i') : null,
                // Tautan dibuat saat render dan berumur pendek; muat ulang halaman bila kedaluwarsa.
                'download_url' => $file->isDownloadable()
                    ? URL::temporarySignedRoute('admin.exports.download', now()->addMinutes($minutes), ['file' => $file->id])
                    : null,
            ]);

        return Inertia::render('exports/index', $result->toInertia());
    }

    /**
     * Hanya pembuat yang bisa mengunduh. Berkas milik orang lain, belum siap, atau kedaluwarsa
     * dijawab 404 (bukan 403) agar keberadaannya tidak terungkap (SEC-033).
     */
    public function download(Request $request, ExportFile $file): StreamedResponse
    {
        abort_unless(
            $file->created_by === $request->user()?->getAuthIdentifier()
                && $file->isDownloadable()
                && Storage::disk(ExportRunner::DISK)->exists((string) $file->path),
            404,
        );

        return Storage::disk(ExportRunner::DISK)->download((string) $file->path, $file->downloadName());
    }
}
