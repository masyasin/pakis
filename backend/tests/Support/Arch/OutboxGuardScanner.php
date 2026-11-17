<?php

namespace Tests\Support\Arch;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Pemindai statis untuk PeriodGuardArchTest (ADR-115, PF-3).
 *
 * Pest arch (`toUse`) hanya melihat dependensi kelas, tidak bisa memastikan sebuah method
 * dipanggil. Pemindai ini membaca token PHP (komentar & string tidak dihitung), meresolusi nama
 * lewat `namespace` + `use` (termasuk alias dan grup), lalu:
 * - berkas yang MERUJUK `AccountingOutbox` (panggilan statis, `app(AccountingOutbox::class)`,
 *   injeksi konstruktor — apa pun) dianggap pemancar event;
 * - pemancar wajib berisi panggilan `PeriodGuard::assertOpen(...)` di berkas yang sama.
 * Sengaja ketat: pemancar yang mendelegasikan PeriodGuard ke kelas lain tetap merah, agar
 * pemeriksaan tanggal kunci terlihat di Action itu sendiri.
 */
final class OutboxGuardScanner
{
    public const OUTBOX = 'App\\Domain\\Accounting\\Outbox\\AccountingOutbox';

    public const GUARD = 'App\\Domain\\Accounting\\Period\\PeriodGuard';

    public const EVENT_MODEL = 'App\\Domain\\Accounting\\Outbox\\Models\\AccountingEvent';

    /**
     * @return array{outbox: bool, guard: bool, event_model: bool, table_name: bool}
     */
    public static function inspect(string $file): array
    {
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($file)),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        [$namespace, $aliases, $useRanges] = self::imports($tokens);

        $found = ['outbox' => false, 'guard' => false, 'event_model' => false, 'table_name' => false];
        foreach ($tokens as $i => $token) {
            if (! is_array($token) || self::inRanges($i, $useRanges)) {
                continue;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING && str_contains($token[1], 'accounting_events')) {
                $found['table_name'] = true;

                continue;
            }
            if (! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                continue;
            }
            // Nama method/properti (`->record`, `::record`) bukan rujukan kelas.
            $previous = $tokens[$i - 1] ?? null;
            if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true)) {
                continue;
            }

            $name = self::resolve($token, $namespace, $aliases);
            if ($name === self::OUTBOX) {
                $found['outbox'] = true;
            } elseif ($name === self::EVENT_MODEL) {
                $found['event_model'] = true;
            } elseif ($name === self::GUARD && self::isCall($tokens, $i, 'assertOpen')) {
                $found['guard'] = true;
            }
        }

        return $found;
    }

    /**
     * Berkas pemancar event tanpa PeriodGuard::assertOpen.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    public static function violations(array $files): array
    {
        return array_values(array_filter($files, function (string $file) {
            $found = self::inspect($file);

            return $found['outbox'] && ! $found['guard'];
        }));
    }

    /**
     * @return list<string> semua berkas .php di bawah folder
     */
    public static function phpFiles(string $dir): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: string, 1: array<string, string>, 2: list<array{int, int}>}
     */
    private static function imports(array $tokens): array
    {
        $namespace = '';
        $aliases = [];
        $ranges = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            // `{$x}` / `${x}` di string dibuka token khusus tetapi ditutup `}` biasa.
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            }
            if (! is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE && $depth === 0) {
                $start = $i;
                $namespace = '';
                while (++$i < $count && is_array($tokens[$i])) {
                    $namespace .= $tokens[$i][1];
                }
                $ranges[] = [$start, $i];

                continue;
            }
            // `use` di tingkat berkas = impor; di dalam kelas = trait, di closure = variabel.
            if ($token[0] !== T_USE || $depth !== 0) {
                continue;
            }

            $start = $i;
            $prefix = '';
            $current = '';
            $alias = null;
            $expectAlias = false;
            while (++$i < $count && $tokens[$i] !== ';') {
                $t = $tokens[$i];
                if ($t === '{') {
                    $prefix = rtrim($current, '\\').'\\';
                    $current = '';
                } elseif ($t === ',' || $t === '}') {
                    self::addAlias($aliases, $prefix.$current, $alias);
                    [$current, $alias, $expectAlias] = ['', null, false];
                } elseif (is_array($t) && $t[0] === T_AS) {
                    $expectAlias = true;
                } elseif (is_array($t) && in_array($t[0], [T_FUNCTION, T_CONST], true)) {
                    continue; // `use function`/`use const` tidak berisi nama kelas yang dicari
                } elseif (is_array($t) && $expectAlias) {
                    $alias = $t[1];
                } elseif (is_array($t) || $t === '\\') {
                    $current .= is_array($t) ? $t[1] : $t;
                }
            }
            self::addAlias($aliases, $prefix.$current, $alias);
            $ranges[] = [$start, $i];
        }

        return [$namespace, $aliases, $ranges];
    }

    /**
     * @param  array<string, string>  $aliases
     */
    private static function addAlias(array &$aliases, string $name, ?string $alias): void
    {
        $name = ltrim(trim($name), '\\');
        if ($name === '' || str_ends_with($name, '\\')) {
            return;
        }
        $aliases[strtolower($alias ?? substr($name, (int) strrpos('\\'.$name, '\\')))] = $name;
    }

    /**
     * @param  array{0: int, 1: string, 2: int}  $token
     * @param  array<string, string>  $aliases
     */
    private static function resolve(array $token, string $namespace, array $aliases): string
    {
        $text = $token[1];
        if ($token[0] === T_NAME_FULLY_QUALIFIED) {
            return ltrim($text, '\\');
        }
        if ($token[0] === T_NAME_RELATIVE) {
            return ltrim($namespace.'\\'.substr($text, strlen('namespace\\')), '\\');
        }

        $first = strtolower(explode('\\', $text, 2)[0]);
        if (isset($aliases[$first])) {
            $rest = str_contains($text, '\\') ? substr($text, strpos($text, '\\')) : '';

            return $aliases[$first].$rest;
        }

        return ltrim($namespace.'\\'.$text, '\\');
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function isCall(array $tokens, int $i, string $method): bool
    {
        $colon = $tokens[$i + 1] ?? null;
        $name = $tokens[$i + 2] ?? null;
        $paren = $tokens[$i + 3] ?? null;

        return is_array($colon) && $colon[0] === T_DOUBLE_COLON
            && is_array($name) && strcasecmp($name[1], $method) === 0
            && $paren === '(';
    }

    /**
     * @param  list<array{int, int}>  $ranges
     */
    private static function inRanges(int $i, array $ranges): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($i >= $from && $i <= $to) {
                return true;
            }
        }

        return false;
    }
}
