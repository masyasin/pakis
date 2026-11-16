<?php

namespace App\Support\Integrity;

use Illuminate\View\Compilers\BladeCompiler;
use Throwable;

/**
 * Bukti bahwa `.php` di `storage/framework/views` benar-benar view Blade terkompilasi (SEC-070).
 * Nama berkas saja tidak cukup — penyerang bisa menamai webshell `<hex>.php`. Syarat:
 *   1. diakhiri komentar `<?php /**PATH <sumber> ENDPATH** / ?>` yang ditulis BladeCompiler::compile();
 *   2. sumbernya SAH: berkas `.blade.*` di bawah `integrity.base` yang tercatat di manifest rilis
 *      dengan sha256 yang sama (resources/views & view Blade di vendor/ ikut di-hash saat deploy),
 *      atau komponen inline yang lolos InlineComponent::isSafe();
 *   3. nama berkas = nama yang akan dibuat BladeCompiler untuk sumber itu (termasuk sufiks echo
 *      format Markdown mail `_<hash>`);
 *   4. isinya PERSIS hasil kompilasi ulang sumbernya.
 * Tanpa manifest (dev lokal) sumber di resources/views/vendor hanya bisa dicek isinya → hasilnya
 * `unverified` (peringatan), bukan `ok`.
 * Akibat samping: view terkompilasi basi (sumber berubah tanpa `view:clear`) ikut dilaporkan —
 * deploy menjalankan `view:cache` ulang (T-046), jadi di produksi itu memang tanda bahaya.
 */
final class CompiledViewVerifier
{
    public const OK = 'ok';

    public const UNVERIFIED = 'unverified';

    public const BAD = 'bad';

    /** Echo format non-bawaan yang dipakai framework (Mail Markdown, Illuminate\Mail\Markdown). */
    private const ECHO_FORMATS = ['new \Illuminate\Support\EncodedHtmlString(%s)'];

    private const FOOTER = '~<\?php /\*\*PATH (.+) ENDPATH\*\*/ \?>$~s';

    private const INLINE_DIR = 'storage/framework/views/';

    public function __construct(private readonly BladeCompiler $compiler) {}

    /**
     * @param  string  $base  akar `integrity.base` (pemisah `/`)
     * @param  array<string, string>|null  $manifest  manifest rilis; null = tidak ada/rusak
     * @return self::OK|self::UNVERIFIED|self::BAD
     */
    public function verify(string $file, string $base, ?array $manifest): string
    {
        $contents = @file_get_contents($file);
        if (! is_string($contents)) {
            return self::BAD;
        }
        $name = basename($file);

        // Komponen inline (Component::createBladeViewFromString): nama = hash isi + isi aman.
        if (str_ends_with($name, '.blade.php')) {
            return hash('xxh128', $contents).'.blade.php' === $name && InlineComponent::isSafe($contents) ? self::OK : self::BAD;
        }

        if (preg_match(self::FOOTER, $contents, $m) !== 1) {
            return self::BAD;
        }
        $source = $m[1];
        $trust = $this->sourceTrust($source, $base, $manifest);
        if ($trust === self::BAD) {
            return self::BAD;
        }

        $format = $this->echoFormatFor($name);
        if ($format === false) {
            return self::BAD;
        }

        try {
            $compiler = clone $this->compiler;
            if ($format !== null) {
                $compiler->setEchoFormat($format);
            }
            if (basename($compiler->getCompiledPath($source)) !== $name) {
                return self::BAD;
            }
            $compiler->setPath($source);
            $expected = $compiler->compileString((string) file_get_contents($source))."<?php /**PATH {$source} ENDPATH**/ ?>";
        } catch (Throwable) {
            return self::BAD;
        }

        return hash_equals($expected, $contents) ? $trust : self::BAD;
    }

    /**
     * @param  array<string, string>|null  $manifest
     * @return self::OK|self::UNVERIFIED|self::BAD
     */
    private function sourceTrust(string $source, string $base, ?array $manifest): string
    {
        // `.blade.php` umumnya; paket bisa memakai ekstensi lain (Wayfinder: `.blade.ts`).
        if (preg_match('/\.blade\.[a-z]{1,10}$/i', $source) !== 1 || ! is_file($source) || is_link($source)) {
            return self::BAD;
        }
        $relative = self::relative($source, $base);
        if ($relative === null) {
            return self::BAD;
        }

        if (str_starts_with($relative, self::INLINE_DIR)) {
            $inline = (string) file_get_contents($source);

            return substr_count($relative, '/') === 3 && hash('xxh128', $inline).'.blade.php' === basename($relative) && InlineComponent::isSafe($inline)
                ? self::OK
                : self::BAD;
        }

        if (! str_starts_with($relative, 'resources/views/') && ! str_starts_with($relative, 'vendor/')) {
            return self::BAD;
        }

        if ($manifest === null) {
            return self::UNVERIFIED;
        }

        $hash = $manifest[$relative] ?? null;

        return $hash !== null && hash_equals($hash, (string) hash_file('sha256', $source)) ? self::OK : self::BAD;
    }

    /** Jalur relatif terhadap base (pemisah `/`), null bila di luar base atau memuat `..`. */
    private static function relative(string $path, string $base): ?string
    {
        $path = strtr($path, ['\\' => '/']);
        $prefix = rtrim($base, '/').'/';
        $inside = PHP_OS_FAMILY === 'Windows' ? stripos($path, $prefix) === 0 : str_starts_with($path, $prefix);
        if (! $inside) {
            return null;
        }
        $relative = substr($path, strlen($prefix));

        return in_array('..', explode('/', $relative), true) ? null : $relative;
    }

    /** null = echo format bawaan, string = format sufiks, false = sufiks tidak dikenal */
    private function echoFormatFor(string $name): string|false|null
    {
        if (preg_match('/^[0-9a-f]+_([0-9a-f]+)\.php$/', $name, $m) !== 1) {
            return null;
        }
        foreach (self::ECHO_FORMATS as $format) {
            if (hash('xxh128', $format) === $m[1]) {
                return $format;
            }
        }

        return false;
    }
}
