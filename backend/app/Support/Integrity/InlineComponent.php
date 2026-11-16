<?php

namespace App\Support\Integrity;

use Illuminate\View\Compilers\BladeCompiler;
use Throwable;

/**
 * Komponen Blade inline (`storage/framework/views/<xxh128>.blade.php`, ditulis
 * Component::createBladeViewFromString dari string `render()` kelas komponen). Berkas ini tidak
 * tercatat di manifest rilis, padahal setiap ekspresi Blade di dalamnya dieksekusi sebagai PHP
 * (`{{ system('…') }}`). Daftar izin ketat (SEC-070), tiga lapis — semuanya harus lolos:
 *   1. tolak lebih dulu tag komponen (`<x-…>`, `<x-slot>`), attribute binding (`:attr="…"`), tag
 *      PHP, dan direktif yang menjalankan/menyertakan kode (`@php`, `@include`, `@props`, …);
 *   2. di level Blade: echo dan argumen `@if/@elseif/@unless/@isset/@empty` hanya akses variabel
 *      sederhana (`$a`, `$a->b`, `$a['k']`);
 *   3. SUMBER KEBENARAN = PHP hasil kompilasi: ditokenisasi, hanya token HTML, `echo`, `e(…)`,
 *      `if/elseif/else/endif/isset/empty`, variabel, akses properti, indeks string/angka, dan
 *      `! ( ) : ;` yang diizinkan. Panggilan fungsi selain `e`, `new`, `::`, `$$`, backtick,
 *      include/require/eval → ditolak.
 * Dipilih daripada "laporkan semua komponen inline": komponen inline bawaan paket di tree ini
 * (Inertia `@if($response) {!! $response->head !!} …`) hanya memakai bentuk sederhana, jadi tidak
 * ada temuan palsu, sedangkan sisipan kode tetap tertangkap.
 */
final class InlineComponent
{
    /** Lapis 1: konstruksi Blade yang bisa menjalankan/menyertakan kode lain. */
    private const DENY = '/<\?|<\/?\s*x[-:]|<\s*x-slot|\s:[\w.-]+\s*=|@(?:php|verbatim|aware|props|class|style|checked|selected|disabled|readonly|required|include\w*|each|inject|eval|once|push\w*|prepend\w*|stack|section|yield|extends|component|use|livewire|vite|method|csrf|env|production|auth|guest|can\w*|cannot|lang|choice|json|js|dd|dump|for\w*|while|switch|case|break|continue|fragment|teleport|session|error)\b/i';

    /** Lapis 2: akses variabel tanpa panggilan: `$a`, `$a->b`, `$a['k']`, `$a[0]`, boleh diawali `!`. */
    private const SIMPLE = '/^\s*!?\s*\$[A-Za-z_]\w*(?:->[A-Za-z_]\w*|\[(?:\'[^\'\\\\]*\'|"[^"\\\\$]*"|\d+)\])*\s*$/';

    private const DIRECTIVES_WITH_ARG = ['if', 'elseif', 'unless', 'isset', 'empty'];

    private const DIRECTIVES_BARE = ['else', 'endif', 'endunless', 'endisset', 'endempty'];

    /** Lapis 3: token PHP hasil kompilasi yang boleh muncul begitu saja. */
    private const FREE_TOKENS = [T_INLINE_HTML, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG, T_WHITESPACE, T_ECHO, T_IF, T_ELSEIF, T_ELSE, T_ENDIF, T_ISSET, T_EMPTY, T_VARIABLE, T_OBJECT_OPERATOR];

    /** Token sebelum `(` yang sah: kondisi, isset/empty, `e(…)`, `! (…)` dari @unless. */
    private const PAREN_AFTER = [T_IF, T_ELSEIF, T_ISSET, T_EMPTY];

    public static function isSafe(string $blade, BladeCompiler $compiler): bool
    {
        if (preg_match(self::DENY, $blade) === 1 || ! self::bladeLevelSafe($blade)) {
            return false;
        }

        try {
            $php = (clone $compiler)->compileString($blade);
        } catch (Throwable) {
            return false;
        }

        return self::compiledSafe($php);
    }

    private static function bladeLevelSafe(string $blade): bool
    {
        // Komentar Blade tidak dieksekusi.
        $blade = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $blade);

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

        preg_match_all('/(?<![\w@])@(\w+)(\s*\(([^()]*)\))?/', $rest, $matches, PREG_SET_ORDER);
        foreach ($matches as $d) {
            $name = strtolower($d[1]);
            // Grup argumen opsional: `$d[3]` hanya ada bila `(...)` tertangkap.
            $arg = $d[3] ?? null;
            if ($arg !== null && in_array($name, self::DIRECTIVES_WITH_ARG, true) && preg_match(self::SIMPLE, $arg) === 1) {
                continue;
            }
            if ($arg === null && in_array($name, self::DIRECTIVES_BARE, true)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /** Lapis 3: tokenisasi PHP hasil kompilasi, daftar izin per token (lihat kelas). */
    private static function compiledSafe(string $php): bool
    {
        $tokens = array_values(array_filter(token_get_all($php), fn ($t) => ! is_array($t) || $t[0] !== T_WHITESPACE));
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $prev = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;

            if (is_string($token)) {
                $ok = match ($token) {
                    '!', ')', ':', ';' => true,
                    '(' => self::isAny($prev, self::PAREN_AFTER) || $prev === '!' || $prev === '(' || self::isEcho($prev, $tokens[$i - 2] ?? null),
                    // Indeks hanya `[ 'string' ]` / `[ 123 ]` tepat setelah variabel/properti/indeks.
                    '[' => (self::isAny($prev, [T_VARIABLE, T_STRING]) || $prev === ']')
                        && self::isAny($next, [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER])
                        && ($tokens[$i + 2] ?? null) === ']',
                    ']' => self::isAny($prev, [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER]),
                    default => false,
                };
                if (! $ok) {
                    return false;
                }

                continue;
            }

            [$id, $text] = $token;
            if (in_array($id, self::FREE_TOKENS, true)) {
                // `$$a` = variabel variabel → nama dinamis, tolak.
                if ($id === T_VARIABLE && $text === '$') {
                    return false;
                }

                continue;
            }

            $ok = match ($id) {
                // Nama properti setelah `->` (bukan panggilan method), atau fungsi escape `e(`.
                T_STRING => (self::isAny($prev, [T_OBJECT_OPERATOR]) && $next !== '(')
                    || ($text === 'e' && $next === '(' && ! self::isAny($prev, [T_OBJECT_OPERATOR])),
                // String/angka hanya sebagai isi indeks `[...]`.
                T_CONSTANT_ENCAPSED_STRING, T_LNUMBER => $prev === '[' && $next === ']'
                    && ($id === T_LNUMBER || ! str_starts_with($text, '"') || ! str_contains($text, '$')),
                default => false,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{0: int, 1: string, 2: int}|string|null  $token
     * @param  list<int>  $ids
     */
    private static function isAny(array|string|null $token, array $ids): bool
    {
        return is_array($token) && in_array($token[0], $ids, true);
    }

    /**
     * `(` milik `e(…)`: token sebelumnya T_STRING `e` yang tidak didahului `->`.
     *
     * @param  array{0: int, 1: string, 2: int}|string|null  $prev
     * @param  array{0: int, 1: string, 2: int}|string|null  $beforePrev
     */
    private static function isEcho(array|string|null $prev, array|string|null $beforePrev): bool
    {
        return is_array($prev) && $prev[0] === T_STRING && $prev[1] === 'e' && ! self::isAny($beforePrev, [T_OBJECT_OPERATOR]);
    }
}
