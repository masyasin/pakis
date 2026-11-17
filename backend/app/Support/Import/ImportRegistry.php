<?php

namespace App\Support\Import;

use App\Domain\Settings\SettingRegistry;
use App\Models\User;
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

    /**
     * Kelas tipe terdaftar TANPA membuat objeknya (aman dipanggil saat rute dimuat).
     *
     * @return array<string, class-string<ImportType>> kunci tipe => kelas
     */
    public static function classes(): array
    {
        $classes = [];
        foreach ((array) config('pakis.imports', []) as $key => $class) {
            if (is_string($key) && is_string($class) && is_subclass_of($class, ImportType::class)) {
                $classes[$key] = $class;
            }
        }

        return $classes;
    }

    /** Tipe untuk segmen rute `/impor/{slug}`, atau null bila tidak ada. Hanya tipe itu yang dibuat. */
    public static function forSlug(string $slug): ?ImportType
    {
        foreach (self::classes() as $key => $class) {
            if ($class::routeSlug() === $slug) {
                return self::resolve($key);
            }
        }

        return null;
    }

    /** Izin payung tipe impor master (15 §5 `admin.master-imports.*`, PA-34(b), T-085). */
    public const MASTER_PERMISSION = 'master_imports.manage';

    /**
     * Boleh memakai tipe ini: izin tipe, DAN untuk tipe master (ber-`routeSlug`, yaitu semua selain
     * impor pegawai Fase 0) juga izin payung `master_imports.manage` (T-085, PA-70).
     */
    public static function allows(User $user, ImportType $type): bool
    {
        return $user->can($type->permission())
            && ($type::routeSlug() === null || $user->can(self::MASTER_PERMISSION));
    }

    /** @return list<ImportType> tipe yang boleh dipakai pengguna (riwayat, kartu "Mulai impor"). */
    public static function allowedFor(User $user): array
    {
        return array_values(array_filter(self::all(), fn (ImportType $t) => self::allows($user, $t)));
    }

    /**
     * Pola `{type}` untuk rute `admin.master-imports.*`: hanya slug terdaftar, agar tidak bentrok
     * dengan `impor/pegawai…`, `impor/profil`, dan `impor/{batch}`. Tanpa tipe → pola yang tidak
     * pernah cocok (bukan pola kosong, yang di Laravel bisa ditafsirkan longgar).
     */
    public static function routePattern(): string
    {
        // Dipanggil dari routes/admin.php: hanya method statis, tidak ada objek tipe yang dibuat.
        $slugs = array_values(array_filter(array_map(fn (string $class) => $class::routeSlug(), self::classes())));

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
