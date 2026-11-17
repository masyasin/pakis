<?php

namespace App\Domain\Inventory\Support;

use App\Domain\Inventory\Models\DisposalReport;
use App\Domain\Inventory\Models\StockOpnameLine;
use Illuminate\Support\Facades\URL;

/**
 * Tautan berkas privat gudang (SEC-108/109, PA-67 k, PA-69; pola izin edar T-071 & berkas kios
 * T-074): foto baris opname, scan BA pemusnahan, dan foto pemusnahan. URL relatif bertanda tangan
 * 5 menit; rutenya (`InventoryFileController`) tetap memeriksa izin + Policy ber-scope, jadi tautan
 * yang bocor ke pengguna lain tetap 404.
 */
final class InventoryFileUrls
{
    /** Umur tautan (SEC-108: "signed URL 5 menit"), sama dengan selfie & berkas izin edar. */
    public const MINUTES = 5;

    public static function opnamePhoto(StockOpnameLine $line): ?string
    {
        return $line->photo_path === null ? null
            : URL::temporarySignedRoute('admin.stock-opnames.lines.photo', now()->addMinutes(self::MINUTES),
                ['opname' => $line->stock_opname_id, 'line' => $line->id], false);
    }

    public static function disposalDocument(DisposalReport $report): ?string
    {
        return $report->document_path === null ? null
            : URL::temporarySignedRoute('admin.disposal-reports.document', now()->addMinutes(self::MINUTES), ['report' => $report->id], false);
    }

    /** @return list<string> */
    public static function disposalPhotos(DisposalReport $report): array
    {
        return array_map(
            fn (int $i) => URL::temporarySignedRoute('admin.disposal-reports.photo', now()->addMinutes(self::MINUTES), ['report' => $report->id, 'index' => $i], false),
            array_keys($report->photo_paths ?? []),
        );
    }
}
