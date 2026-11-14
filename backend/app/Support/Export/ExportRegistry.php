<?php

namespace App\Support\Export;

use InvalidArgumentException;

/**
 * Peta kunci listing => kelas ExportableListing (`config('pakis.exports')`). Job ekspor hanya menyimpan
 * kunci, lalu menemukan kelasnya lagi di sini.
 */
final class ExportRegistry
{
    public static function resolve(string $listingKey): ExportableListing
    {
        $class = config("pakis.exports.{$listingKey}");

        if (! is_string($class) || ! is_subclass_of($class, ExportableListing::class)) {
            throw new InvalidArgumentException("Listing [{$listingKey}] tidak terdaftar sebagai ExportableListing.");
        }

        /** @var ExportableListing */
        return app($class);
    }

    public static function syncMaxRows(): int
    {
        return (int) config('pakis.export.sync_max_rows');
    }

    public static function maxRows(): int
    {
        return (int) config('pakis.export.max_rows');
    }

    public static function massExportAlertRows(): int
    {
        return (int) config('pakis.export.mass_export_alert_rows');
    }

    public static function retentionDays(): int
    {
        return (int) config('pakis.export.retention_days');
    }
}
