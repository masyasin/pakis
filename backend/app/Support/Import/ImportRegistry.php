<?php

namespace App\Support\Import;

use App\Domain\Settings\SettingRegistry;
use InvalidArgumentException;

/**
 * Peta kunci tipe => kelas ImportType (`config('pakis.imports')`, pola ExportRegistry). Batch dan
 * job hanya menyimpan kunci tipe, lalu menemukan kelasnya lagi di sini.
 */
final class ImportRegistry
{
    public static function resolve(string $type): ImportType
    {
        $class = config("pakis.imports.{$type}");

        if (! is_string($class) || ! is_subclass_of($class, ImportType::class)) {
            throw new InvalidArgumentException("Tipe impor [{$type}] tidak terdaftar.");
        }

        /** @var ImportType */
        return app($class);
    }

    public static function forBatch(ImportBatch $batch): ImportType
    {
        return self::resolve($batch->type);
    }

    public static function has(string $type): bool
    {
        $class = config("pakis.imports.{$type}");

        return is_string($class) && is_subclass_of($class, ImportType::class);
    }

    /** @return list<ImportType> */
    public static function all(): array
    {
        $types = [];
        foreach (array_keys((array) config('pakis.imports', [])) as $key) {
            if (is_string($key) && self::has($key)) {
                $types[] = self::resolve($key);
            }
        }

        return $types;
    }

    /** Tipe untuk segmen rute `/impor/{slug}`, atau null bila tidak ada. */
    public static function forSlug(string $slug): ?ImportType
    {
        foreach (self::all() as $type) {
            if ($type->routeSlug() === $slug) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Pola `{type}` untuk rute `admin.master-imports.*`: hanya slug terdaftar, agar tidak bentrok
     * dengan `impor/pegawai…`, `impor/profil`, dan `impor/{batch}`. Tanpa tipe → pola yang tidak
     * pernah cocok (bukan pola kosong, yang di Laravel bisa ditafsirkan longgar).
     */
    public static function routePattern(): string
    {
        $slugs = array_values(array_filter(array_map(fn (ImportType $t) => $t->routeSlug(), self::all())));

        return $slugs === [] ? '(?!)' : implode('|', array_map(fn (string $s) => preg_quote($s, '/'), $slugs));
    }

    /**
     * Batas baris data per tipe (BR-170): `import.max_rows_<type>` bila kunci itu terdaftar
     * (mis. saldo awal, T-086), selain itu `import.max_rows` (Fase 0).
     */
    public static function maxRows(string $type): int
    {
        $key = "import.max_rows_{$type}";

        return (int) setting(array_key_exists($key, SettingRegistry::all()) ? $key : 'import.max_rows');
    }
}
