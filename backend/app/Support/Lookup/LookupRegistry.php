<?php

namespace App\Support\Lookup;

use LogicException;

/**
 * Daftar sumber lookup. Setiap modul mendaftarkan sumbernya sendiri (AppServiceProvider), sehingga
 * kerangka tidak perlu tahu modul mana saja yang ada.
 */
final class LookupRegistry
{
    /** @var array<string, class-string<LookupSource>> */
    private array $sources = [];

    /**
     * @param  class-string<LookupSource>  $class
     */
    public function register(string $key, string $class): void
    {
        if (preg_match('/^[a-z][a-z_]{1,49}$/', $key) !== 1) {
            throw new LogicException("Kunci lookup [{$key}] harus huruf kecil/garis bawah (dipakai di URL).");
        }

        if (isset($this->sources[$key])) {
            throw new LogicException("Sumber lookup [{$key}] sudah terdaftar.");
        }

        $this->sources[$key] = $class;
    }

    public function resolve(string $key): ?LookupSource
    {
        $class = $this->sources[$key] ?? null;

        return $class === null ? null : app($class);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->sources);
    }
}
