<?php

namespace App\Support\Integrity;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Integritas berkas (SEC-070, T-045). Manifest rilis = peta `jalur relatif → sha256` untuk folder
 * `integrity.hashed` (app/, public/), ditulis saat deploy. Pemeriksaan harian melaporkan berkas
 * yang berubah, hilang, atau bertambah, serta berkas `.php` di `storage/` (tidak pernah sah, kecuali
 * view Blade terkompilasi) dan `.php` baru di `public/` — pola umum web shell / sisipan judi online.
 */
final class FileIntegrity
{
    /** View Blade terkompilasi Laravel: `storage/framework/views/<hash hex>.php`. */
    private const COMPILED_VIEW = '#^storage/framework/views/[0-9a-f]{32,64}\.php$#';

    /** @return array<string, string> jalur relatif (pemisah `/`) → sha256 */
    public function snapshot(): array
    {
        $hashes = [];
        foreach ((array) config('integrity.hashed') as $dir) {
            foreach ($this->files((string) $dir) as $relative => $file) {
                $hashes[$relative] = (string) hash_file('sha256', $file->getPathname());
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
     * @return array{manifest: bool, changed: list<string>, missing: list<string>, added: list<string>, php: list<string>}
     */
    public function check(): array
    {
        $manifest = $this->readManifest();
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
            'manifest' => $manifest !== null,
            'changed' => $changed,
            'missing' => $missing,
            'added' => $added,
            'php' => $this->suspiciousPhp($manifest),
        ];
    }

    /**
     * `.php` di folder `integrity.no_php`: di storage selalu mencurigakan (kecuali view terkompilasi);
     * di public hanya yang tidak ada di manifest (tanpa manifest: semua kecuali `public/index.php`).
     *
     * @param  array<string, string>|null  $manifest
     * @return list<string>
     */
    private function suspiciousPhp(?array $manifest): array
    {
        $found = [];
        foreach ((array) config('integrity.no_php') as $dir) {
            foreach ($this->files((string) $dir) as $relative => $file) {
                if (strtolower($file->getExtension()) !== 'php' && ! preg_match('/\.(phtml|phar|php\d)$/i', $relative)) {
                    continue;
                }
                if (preg_match(self::COMPILED_VIEW, $relative) === 1) {
                    continue;
                }
                if (str_starts_with($relative, 'public/')) {
                    $known = $manifest !== null ? array_key_exists($relative, $manifest) : $relative === 'public/index.php';
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

    /** @return array<string, string>|null */
    private function readManifest(): ?array
    {
        $path = $this->manifestPath();
        if (! is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! is_array($data['files'] ?? null)) {
            return null;
        }

        return array_filter($data['files'], 'is_string');
    }

    private function manifestPath(): string
    {
        return (string) config('integrity.manifest');
    }

    /** Jalur dengan pemisah `/` (Windows memakai `\`), agar manifest sama di semua OS. */
    private static function slashes(string $path): string
    {
        return strtr($path, ['\\' => '/']);
    }

    /** @return iterable<string, SplFileInfo> */
    private function files(string $dir): iterable
    {
        $base = rtrim(self::slashes((string) config('integrity.base')), '/');
        $root = $base.'/'.trim($dir, '/');
        if (! is_dir($root)) {
            return;
        }
        $ignore = array_map(fn ($p) => trim((string) $p, '/').'/', (array) config('integrity.ignore'));

        // Symlink tidak diikuti: `public/storage` menunjuk isi unggahan, bukan kode rilis.
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = ltrim(substr(self::slashes($file->getPathname()), strlen($base)), '/');
            foreach ($ignore as $prefix) {
                if (str_starts_with($relative.'/', $prefix) || str_starts_with($relative, $prefix)) {
                    continue 2;
                }
            }
            yield $relative => $file;
        }
    }
}
