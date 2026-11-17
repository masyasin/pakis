<?php

namespace App\Domain\Settings;

use App\Domain\Settings\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Baca/tulis nilai setting (BR-020–BR-024, 03 §4.2).
 *
 * - Nilai tersimpan per grup di cache (`settings.group.<grup>`) dan dibersihkan setiap grup disimpan.
 * - Didaftarkan `scoped`: memo di objek ini hidup selama satu request/job saja, sehingga worker
 *   antrean yang berjalan lama tetap membaca perubahan admin di job berikutnya.
 * - Kunci tanpa baris = default registri. Bila tabel belum ada (instalasi, `migrate` pertama),
 *   default dipakai agar aplikasi & artisan tetap bisa boot.
 */
final class SettingStore
{
    public const CACHE_PREFIX = 'settings.group.';

    /** @var array<string, array<string, mixed>> grup => [kunci => nilai mentah tersimpan] */
    private array $memo = [];

    public function get(string $key): mixed
    {
        $definition = SettingRegistry::get($key);
        $stored = $this->storedGroup($definition->group());

        return array_key_exists($key, $stored)
            ? $definition->cast($stored[$key])
            : $definition->cast($definition->default);
    }

    /**
     * Semua nilai efektif satu grup (tersimpan atau default).
     *
     * @return array<string, mixed>
     */
    public function group(string $group): array
    {
        $values = [];
        foreach (SettingRegistry::group($group) as $key => $definition) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    public function isCustomized(string $key): bool
    {
        return array_key_exists($key, $this->storedGroup(SettingRegistry::get($key)->group()));
    }

    /**
     * Tulis nilai yang SUDAH divalidasi. Dipanggil UpdateSettingGroup (di dalam transaksinya) atau test.
     *
     * @param  array<string, mixed>  $values  kunci => nilai
     * @param  list<string>  $reset  kunci yang dikembalikan ke default (barisnya dihapus)
     */
    public function write(array $values, array $reset = [], ?int $userId = null): void
    {
        $groups = [];

        foreach ($values as $key => $value) {
            $definition = SettingRegistry::get($key);
            if ($value === null) {
                // Cast json Eloquent meneruskan null sebagai NULL SQL, padahal kolom `value` NOT NULL;
                // "kosong" disimpan sebagai JSON `null` agar setting nullable (mis. accounting.lock_date,
                // T-077) bisa dikosongkan lagi lewat form.
                $now = now();
                Setting::query()->upsert(
                    [['key' => $key, 'value' => 'null', 'updated_by' => $userId, 'created_at' => $now, 'updated_at' => $now]],
                    ['key'],
                    ['value', 'updated_by', 'updated_at'],
                );
            } else {
                Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
            }
            $groups[$definition->group()] = true;
        }

        foreach ($reset as $key) {
            Setting::query()->where('key', SettingRegistry::get($key)->key)->delete();
            $groups[SettingRegistry::get($key)->group()] = true;
        }

        // Bersihkan cache setelah transaksi berhasil; bila gagal, cache lama (benar) tetap dipakai.
        DB::afterCommit(function () use ($groups) {
            foreach (array_keys($groups) as $group) {
                $this->forget($group);
            }
        });
    }

    public function forget(string $group): void
    {
        Cache::forget(self::CACHE_PREFIX.$group);
        unset($this->memo[$group]);
    }

    /**
     * @return array<string, mixed>
     */
    private function storedGroup(string $group): array
    {
        if (isset($this->memo[$group])) {
            return $this->memo[$group];
        }

        try {
            $stored = Cache::rememberForever(self::CACHE_PREFIX.$group, fn () => Setting::query()
                ->where('key', 'like', $group.'.%')
                ->pluck('value', 'key')
                ->all());
        } catch (QueryException $e) {
            // Tabel settings/cache belum dibuat: default registri, jangan disimpan ke memo.
            Log::debug('Setting memakai default: tabel belum tersedia.', ['group' => $group]);

            return [];
        }

        return $this->memo[$group] = $stored;
    }
}
