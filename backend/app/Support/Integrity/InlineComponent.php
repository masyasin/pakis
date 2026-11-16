<?php

namespace App\Support\Integrity;

/**
 * Komponen Blade inline (`storage/framework/views/<xxh128>.blade.php`, ditulis
 * Component::createBladeViewFromString dari string `render()` kelas komponen). Berkas ini tidak
 * tercatat di manifest rilis, padahal setiap ekspresi Blade di dalamnya dieksekusi sebagai PHP
 * (`{{ system('…') }}`). Daftar izin ketat (SEC-070): hanya HTML + echo/@if atas akses variabel
 * sederhana (`$a`, `$a->b`, `$a['k']`). Yang lain — tag PHP, `@php`, panggilan fungsi, direktif di
 * luar daftar — dianggap tidak aman dan dilaporkan.
 *
 * Dipilih daripada "laporkan semua komponen inline": komponen inline bawaan paket di tree ini
 * (Inertia `@if($response) {!! $response->head !!} …`) hanya memakai bentuk sederhana, jadi tidak
 * ada temuan palsu, sedangkan sisipan kode tetap tertangkap.
 */
final class InlineComponent
{
    /** Akses variabel tanpa panggilan: `$a`, `$a->b`, `$a['k']`, `$a[0]`, boleh diawali `!`. */
    private const SIMPLE = '/^\s*!?\s*\$[A-Za-z_]\w*(?:->[A-Za-z_]\w*|\[(?:\'[^\'\\\\]*\'|"[^"\\\\$]*"|\d+)\])*\s*$/';

    private const DIRECTIVES_WITH_ARG = ['if', 'elseif', 'unless', 'isset', 'empty'];

    private const DIRECTIVES_BARE = ['else', 'endif', 'endunless', 'endisset', 'endempty'];

    public static function isSafe(string $blade): bool
    {
        if (preg_match('/<\?|@php|@verbatim/i', $blade) === 1) {
            return false;
        }

        // Komentar Blade tidak dieksekusi.
        $blade = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $blade);

        // Echo: setiap isi {{ }} / {!! !!} harus akses variabel sederhana.
        $unsafe = false;
        $rest = preg_replace_callback('/\{!!(.*?)!!\}|\{\{(.*?)\}\}/s', function (array $m) use (&$unsafe) {
            $expr = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            if (preg_match(self::SIMPLE, $expr) !== 1) {
                $unsafe = true;
            }

            return '';
        }, $blade);
        if (! is_string($rest) || $unsafe || str_contains($rest, '{{') || str_contains($rest, '{!!')) {
            return false;
        }

        // Direktif: `@nama` di awal baris/setelah spasi atau tag. Argumen = akses variabel sederhana.
        preg_match_all('/(?<![\w@])@(\w+)(\s*\(([^()]*)\))?/', $rest, $matches, PREG_SET_ORDER);
        foreach ($matches as $d) {
            $name = strtolower($d[1]);
            // Grup argumen opsional: `$d[3]` hanya ada bila `(...)` tertangkap.
            $arg = $d[3] ?? null;
            $hasArg = $arg !== null;
            if ($arg !== null && in_array($name, self::DIRECTIVES_WITH_ARG, true) && preg_match(self::SIMPLE, $arg) === 1) {
                continue;
            }
            if (! $hasArg && in_array($name, self::DIRECTIVES_BARE, true)) {
                continue;
            }

            return false;
        }

        // Kurung bersarang di argumen direktif (mis. `@if(f($x))`) tidak tertangkap pola di atas.
        return preg_match('/@(if|elseif|unless|isset|empty)\s*\([^)]*\(/i', $rest) !== 1;
    }
}
