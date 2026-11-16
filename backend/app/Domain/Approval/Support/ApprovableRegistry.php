<?php

namespace App\Domain\Approval\Support;

use App\Domain\Approval\Contracts\Approvable;
use Illuminate\Database\Eloquent\Model;

/**
 * Registri jenis dokumen approval (`config/pakis.php` `approvables`, 15 §4.2.1). Tiap jenis punya
 * label, kunci `conditions` yang diizinkan di aturan, dan kelas model Approvable. Model diisi task
 * pemilik dokumen (T-074, T-079, ...); selama `null`, aturan sudah bisa disiapkan Owner tetapi belum
 * ada dokumen yang memakainya.
 */
final class ApprovableRegistry
{
    /** @return array<string, array{label?: string, model?: class-string|null, conditions?: list<string>}> */
    private static function all(): array
    {
        $types = config('pakis.approvables', []);

        return is_array($types) ? $types : [];
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $type): bool
    {
        return array_key_exists($type, self::all());
    }

    public static function label(string $type): string
    {
        return (string) (self::all()[$type]['label'] ?? $type);
    }

    /** @return list<string> */
    public static function conditions(string $type): array
    {
        return self::all()[$type]['conditions'] ?? [];
    }

    /** @return class-string|null */
    public static function modelClass(string $type): ?string
    {
        $class = self::all()[$type]['model'] ?? null;

        return is_string($class) ? $class : null;
    }

    /** @return list<array{value: string, label: string, conditions: list<string>}> */
    public static function options(): array
    {
        return array_map(fn (string $type) => [
            'value' => $type,
            'label' => self::label($type),
            'conditions' => self::conditions($type),
        ], self::types());
    }

    /**
     * Muat ulang dokumen (dikunci bila `$lock`) untuk callback keputusan. Null bila model jenis ini
     * belum terdaftar atau dokumennya sudah tidak ada.
     */
    public static function find(string $type, int $id, bool $lock = false): ?Approvable
    {
        $class = self::modelClass($type);
        if ($class === null || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        $query = $class::query();
        if ($lock) {
            $query->lockForUpdate();
        }
        $document = $query->find($id);

        return $document instanceof Approvable ? $document : null;
    }
}
