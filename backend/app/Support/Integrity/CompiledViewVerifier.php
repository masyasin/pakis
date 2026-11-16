<?php

namespace App\Support\Integrity;

use Illuminate\View\Compilers\BladeCompiler;
use Throwable;

/**
 * Bukti bahwa `.php` di `storage/framework/views` benar-benar view Blade terkompilasi (SEC-070).
 * Nama berkas saja tidak cukup — penyerang bisa menamai webshell `<hex>.php`. Syarat:
 *   1. diakhiri komentar `<?php /**PATH <sumber> ENDPATH** / ?>` yang ditulis BladeCompiler::compile();
 *   2. sumbernya berkas `.blade.*` nyata di `resources/views`, `vendor/`, atau view komponen inline
 *      di folder view terkompilasi itu sendiri (di bawah `integrity.base`);
 *   3. nama berkas = nama yang akan dibuat BladeCompiler untuk sumber itu (termasuk sufiks echo
 *      format Markdown mail `_<hash>`);
 *   4. isinya PERSIS hasil kompilasi ulang sumbernya. Webshell yang meniru nama + komentar tetap
 *      gagal di sini.
 * View komponen inline (`<xxh128 isi>.blade.php`, Component::createBladeViewFromString) sah bila
 * namanya = hash isinya dan tidak memuat tag PHP.
 * Akibat samping: view terkompilasi basi (sumber berubah tanpa `view:clear`) ikut dilaporkan —
 * deploy menjalankan `view:cache` ulang (T-046), jadi di produksi itu memang tanda bahaya.
 */
final class CompiledViewVerifier
{
    /** Echo format non-bawaan yang dipakai framework (Mail Markdown, Illuminate\Mail\Markdown). */
    private const ECHO_FORMATS = ['new \Illuminate\Support\EncodedHtmlString(%s)'];

    private const FOOTER = '~<\?php /\*\*PATH (.+) ENDPATH\*\*/ \?>$~s';

    public function __construct(private readonly BladeCompiler $compiler) {}

    public function isGenuine(string $file, string $base): bool
    {
        $contents = @file_get_contents($file);
        if (! is_string($contents)) {
            return false;
        }
        $name = basename($file);

        if (str_ends_with($name, '.blade.php')) {
            return hash('xxh128', $contents).'.blade.php' === $name && preg_match('/<\?(php|=)/i', $contents) !== 1;
        }

        if (preg_match(self::FOOTER, $contents, $m) !== 1 || ! $this->allowedSource($m[1], $base)) {
            return false;
        }
        $source = $m[1];

        $format = $this->echoFormatFor($name);
        if ($format === false) {
            return false;
        }

        try {
            $compiler = clone $this->compiler;
            if ($format !== null) {
                $compiler->setEchoFormat($format);
            }
            if (basename($compiler->getCompiledPath($source)) !== $name) {
                return false;
            }
            $compiler->setPath($source);
            $expected = $compiler->compileString((string) file_get_contents($source))."<?php /**PATH {$source} ENDPATH**/ ?>";
        } catch (Throwable) {
            return false;
        }

        return hash_equals($expected, $contents);
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

    private function allowedSource(string $source, string $base): bool
    {
        // `.blade.php` umumnya; paket bisa memakai ekstensi lain (Wayfinder: `.blade.ts`).
        if (preg_match('/\.blade\.[a-z]{1,10}$/i', $source) !== 1 || ! is_file($source) || is_link($source)) {
            return false;
        }
        $real = realpath($source);
        if ($real === false) {
            return false;
        }
        $real = self::normalize($real);

        foreach (['resources/views/', 'vendor/', 'storage/framework/views/'] as $root) {
            $dir = realpath($base.'/'.$root);
            if ($dir !== false && str_starts_with($real, self::normalize($dir).'/')) {
                return true;
            }
        }

        return false;
    }

    /** Pemisah `/`; huruf kecil di Windows (sistem berkas tidak peka huruf besar). */
    private static function normalize(string $path): string
    {
        $path = rtrim(strtr($path, ['\\' => '/']), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
