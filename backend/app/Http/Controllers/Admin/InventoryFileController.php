<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Inventory\Models\DisposalReport;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockOpnameLine;
use App\Http\Controllers\Controller;
use App\Support\Upload\FileKind;
use App\Support\Upload\UploadTarget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Berkas privat gudang (SEC-108/109, PA-67 k, PA-69): foto baris opname, scan BA & foto pemusnahan.
 * Disajikan dari disk privat lewat URL bertanda tangan berumur pendek (`signed:relative` + izin
 * modul) DAN Policy ber-scope Stok, jadi di luar scope → 404 walau tautannya sah. Tautan dibuat
 * `InventoryFileUrls`.
 */
class InventoryFileController extends Controller
{
    public function opnamePhoto(Request $request, StockOpname $opname, StockOpnameLine $line): StreamedResponse
    {
        abort_unless($line->stock_opname_id === $opname->id, 404);
        abort_unless(Gate::forUser($request->user())->allows('view', $opname), 404);

        return self::stream($line->photo_path, "opname-{$opname->id}-{$line->id}");
    }

    public function disposalDocument(Request $request, DisposalReport $report): StreamedResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $report), 404);

        return self::stream($report->document_path, "ba-{$report->id}");
    }

    public function disposalPhoto(Request $request, DisposalReport $report, int $index): StreamedResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $report), 404);
        $paths = $report->photo_paths ?? [];

        return self::stream($paths[$index] ?? null, "pemusnahan-{$report->id}-".($index + 1));
    }

    private static function stream(?string $path, string $name): StreamedResponse
    {
        // Disk yang sama dengan tujuan unggah privat (SEC-109), bukan disk publik.
        $disk = Storage::disk(UploadTarget::privatePhoto('x')->disk);
        abort_unless($path !== null && $disk->exists($path), 404);
        $kind = FileKind::tryFrom(pathinfo($path, PATHINFO_EXTENSION));
        abort_if($kind === null, 404);

        return $disk->response($path, "{$name}.{$kind->value}", [
            'Content-Type' => $kind->mime(),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
