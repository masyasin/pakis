<?php

namespace App\Support\Integrity;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Integritas berkas (SEC-070, T-045). Manifest rilis = peta `jalur relatif → sha256` untuk folder
 * `integrity.hashed` (app/, public/), ditulis saat deploy. Pemeriksaan harian melaporkan berkas
 * yang berubah, hilang, atau bertambah, serta berkas "bisa dieksekusi" di folder `integrity.no_php`
 * — pola umum web shell / sisipan judi online:
 *   - ekstensi PHP & turunannya (php, php3–8, pht, phtml, phps, phpt, phar, inc), termasuk ekstensi
 *     ganda seperti `x.php.jpg` (Apache dengan konfigurasi longgar tetap mengeksekusinya);
 *   - `.htaccess` dan `.user.ini` (bisa mengubah handler PHP folder itu);
 *   - symlink (bisa menunjuk ke kode di luar folder yang diperiksa), kecuali `integrity.ignore`.
 * Di `storage/` semua itu selalu mencurigakan, kecuali view Blade terkompilasi yang terbukti sah
 * (CompiledViewVerifier). Di `public/` hanya yang tidak ada di manifest.
 */
final class FileIntegrity
{
    /** Ekstensi PHP-like di mana pun dalam nama (bukan hanya akhiran). */
    private const PHP_LIKE = '/\.(ph(p\d?|t|tml|ps|pt|ar)|inc)(\.|$)/i';

    /** Berkas konfigurasi server yang bisa menyalakan eksekusi PHP. */
    private const SERVER_CONFIG = ['.htaccess', '.user.ini'];

    private const COMPILED_VIEWS = 'storage/framework/views/';

    /** Tanpa manifest, berkas public yang dikenal hanya ini (bawaan Laravel). */
    private const PUBLIC_DEFAULTS = ['public/index.php', 'public/.htaccess'];

    public function __construct(private readonly CompiledViewVerifier $views) {}

    /** @return array<string, string> jalur relatif (pemisah `/`) → sha256 */
    public function snapshot(): array
    {
        $hashes = [];
        foreach ((array) config('integrity.hashed') as $dir) {
            foreach ($this->files((string) $dir) as $relative => $file) {
                if (! $file->isLink()) {
                    $hashes[$relative] = (string) hash_file('sha256', $file->getPathname());
                }
            }
        }
        ksort($hashes);

        return $hashes;
    }

    public function writeManifest(): int
    {
        $hashes = $this->snapshot();
        file_put_contents($this->manifestPath(), json_encode(['generated_at' => now()->toIso8601String(), 'files' => $hashes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return count($hashes);
    }

    /**
     * @return array{manifest: 'ok'|'missing'|'invalid', changed: list<string>, missing: list<string>, added: list<string>, php: list<string>}
     */
    public function check(): array
    {
        [$status, $manifest] = $this->readManifest();
        $current = $this->snapshot();

        $changed = $missing = $added = [];
        if ($manifest !== null) {
            foreach ($manifest as $path => $hash) {
                if (! array_key_exists($path, $current)) {
                    $missing[] = $path;
                } elseif (! hash_equals($hash, $current[$path])) {
                    $changed[] = $path;
                }
            }
            $added = array_values(array_diff(array_keys($current), array_keys($manifest)));
        }

        return [
            'manifest' => $status,
            'changed' => $changed,
            'missing' => $missing,
            'added' => $added,
            'php' => $this->suspicious($manifest),
        ];
    }

    /**
     * @param  array<string, string>|null  $manifest
     * @return list<string>
     */
    private function suspicious(?array $manifest): array
    {
        $base = $this->base();
        $found = [];
        foreach ((array) config('integrity.no_php') as $dir) {
            foreach ($this->files((string) $dir) as $relative => $file) {
                if ($file->isLink()) {
                    $found[] = $relative.' (symlink)';

                    continue;
                }
                $name = $file->getFilename();
                if (preg_match(self::PHP_LIKE, $name) !== 1 && ! in_array(strtolower($name), self::SERVER_CONFIG, true)) {
                    continue;
                }
                if (str_starts_with($relative, self::COMPILED_VIEWS) && $this->views->isGenuine($file->getPathname(), $base)) {
                    continue;
                }
                if (str_starts_with($relative, 'public/')) {
                    $known = $manifest !== null ? array_key_exists($relative, $manifest) : in_array($relative, self::PUBLIC_DEFAULTS, true);
                    if ($known) {
                        continue;
                    }
                }
                $found[] = $relative;
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Manifest rusak dibedakan dari tidak ada: rusak selalu temuan (bisa jadi disengaja untuk
     * mematikan cek hash), tidak ada = langkah deploy terlewat.
     *
     * @return array{0: 'ok'|'missing'|'invalid', 1: array<string, string>|null}
     */
    private function readManifest(): array
    {
        $path = $this->manifestPath();
        if (! is_file($path)) {
            return ['missing', null];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! is_array($data['files'] ?? null)) {
            return ['invalid', null];
        }
        $files = [];
        foreach ($data['files'] as $file => $hash) {
            if (! is_string($file) || ! is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                return ['invalid', null];
            }
            $files[$file] = $hash;
        }

        return ['ok', $files];
    }

    private function manifestPath(): string
    {
        return (string) config('integrity.manifest');
    }

    private function base(): string
    {
        return rtrim(self::slashes((string) config('integrity.base')), '/');
    }

    /** Jalur dengan pemisah `/` (Windows memakai `\`), agar manifest sama di semua OS. */
    private static function slashes(string $path): string
    {
        return strtr($path, ['\\' => '/']);
    }

    /**
     * Berkas & symlink di `$dir` (symlink dilaporkan, tidak diikuti). `integrity.ignore` dilewati.
     *
     * @return iterable<string, SplFileInfo>
     */
    private function files(string $dir): iterable
    {
        $base = $this->base();
        $root = $base.'/'.trim($dir, '/');
        if (! is_dir($root) || is_link($root)) {
            return;
        }
        $ignore = array_map(fn ($p) => trim((string) $p, '/'), (array) config('integrity.ignore'));

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo) {
                continue;
            }
            $relative = ltrim(substr(self::slashes($file->getPathname()), strlen($base)), '/');
            foreach ($ignore as $prefix) {
                if ($relative === $prefix || str_starts_with($relative, $prefix.'/')) {
                    continue 2;
                }
            }
            // Folder biasa ditelusuri iterator; symlink (ke berkas atau folder) dilaporkan apa adanya.
            if ($file->isLink() || $file->isFile()) {
                yield $relative => $file;
            }
        }
    }
}
