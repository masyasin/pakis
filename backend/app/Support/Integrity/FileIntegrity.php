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

    /** Penanda nilai manifest untuk symlink: `link:<target>` (target berubah = berkas berubah). */
    private const LINK_PREFIX = 'link:';

    /**
     * Isi `integrity.hashed` seluruhnya + view Blade di `integrity.views`. Symlink dicatat sebagai
     * `link:<target>`, bukan dibuang: symlink baru/berubah di app/ atau public/ menjadi temuan.
     *
     * @return array<string, string> jalur relatif (pemisah `/`) → sha256 atau `link:<target>`
     */
    public function snapshot(): array
    {
        $hashes = [];
        foreach ((array) config('integrity.hashed') as $dir) {
            foreach ($this->files((string) $dir) as $relative => $file) {
                $hashes[$relative] = self::fingerprint($file);
            }
        }
        foreach ((array) config('integrity.views') as $dir) {
            foreach ($this->files((string) $dir) as $relative => $file) {
                if (preg_match('/\.blade\.[a-z]{1,10}$/i', $relative) === 1) {
                    $hashes[$relative] = self::fingerprint($file);
                }
            }
        }
        ksort($hashes);

        return $hashes;
    }

    private static function fingerprint(SplFileInfo $file): string
    {
        if ($file->isLink()) {
            return self::LINK_PREFIX.self::slashes((string) readlink($file->getPathname()));
        }

        return (string) hash_file('sha256', $file->getPathname());
    }

    public function writeManifest(): int
    {
        $hashes = $this->snapshot();
        file_put_contents($this->manifestPath(), json_encode(['generated_at' => now()->toIso8601String(), 'files' => $hashes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return count($hashes);
    }

    /**
     * @return array{manifest: 'ok'|'missing'|'invalid', roots: list<string>, changed: list<string>, missing: list<string>, added: list<string>, php: list<string>, unverified: list<string>}
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

        [$php, $unverified] = $this->suspicious($manifest);

        return [
            'manifest' => $status,
            'roots' => $this->badRoots(),
            'changed' => $changed,
            'missing' => $missing,
            'added' => $added,
            'php' => $php,
            'unverified' => $unverified,
        ];
    }

    /**
     * Folder yang dikonfigurasi tapi tidak ada atau berupa symlink: pemeriksaannya diam-diam
     * kosong, jadi dilaporkan (fail-closed).
     *
     * @return list<string>
     */
    private function badRoots(): array
    {
        $bad = [];
        $dirs = array_unique(array_merge((array) config('integrity.hashed'), (array) config('integrity.views'), (array) config('integrity.no_php')));
        foreach ($dirs as $dir) {
            $root = $this->base().'/'.trim((string) $dir, '/');
            if (is_link($root)) {
                $bad[] = $dir.' (symlink)';
            } elseif (! is_dir($root)) {
                $bad[] = $dir.' (tidak ada)';
            }
        }
        sort($bad);

        return $bad;
    }

    /**
     * @param  array<string, string>|null  $manifest
     * @return array{0: list<string>, 1: list<string>} [temuan, view yang tidak bisa diverifikasi tanpa manifest]
     */
    private function suspicious(?array $manifest): array
    {
        $base = $this->base();
        $found = $unverified = [];
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
                if (str_starts_with($relative, self::COMPILED_VIEWS)) {
                    $verdict = $this->views->verify($file->getPathname(), $base, $manifest);
                    if ($verdict === CompiledViewVerifier::OK) {
                        continue;
                    }
                    if ($verdict === CompiledViewVerifier::UNVERIFIED) {
                        $unverified[] = $relative;

                        continue;
                    }
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
        sort($unverified);

        return [$found, $unverified];
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
            if (! is_string($file) || ! is_string($hash) || preg_match('/^([0-9a-f]{64}|link:.+)$/', $hash) !== 1) {
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
