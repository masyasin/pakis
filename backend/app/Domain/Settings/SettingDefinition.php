<?php

namespace App\Domain\Settings;

use Closure;
use InvalidArgumentException;

/**
 * Definisi satu kunci setting (03 §4.2): tipe, default, validasi, label, dan penanda publik.
 * Nilai di sini adalah sumber kebenaran kode; dicocokkan dengan 08 §2 oleh SettingRegistryTest.
 *
 * Penanda PA-19 (T-067), ditegakkan di UpdateSettingGroup (bukan hanya di UI):
 * - `locked`: hanya super_admin yang boleh mengubah, wajib konfirmasi password, diaudit.
 * - `oneWay`: bool yang hanya boleh true → false (mis. "saldo awal masih dibuka").
 */
final readonly class SettingDefinition
{
    /**
     * @param  list<string|object>  $rules  aturan tambahan selain aturan dasar tipe
     * @param  list<string|int>  $options  untuk enum / multi_enum
     * @param  (Closure(mixed, array<string, mixed>): ?string)|null  $check  validasi kustom:
     *                                                                       terima nilai & semua nilai grup, kembalikan pesan galat atau null
     * @param  int|string|null  $min  batas bawah: angka (int/decimal/money), panjang (string), atau jumlah butir (daftar).
     *                                String desimal (mis. `'0.5'`) hanya untuk tipe decimal/money (T-074): dibandingkan
     *                                lewat DecimalString, tidak pernah float.
     * @param  int|string|null  $max  batas atas, aturan sama dengan `$min`
     */
    public function __construct(
        public string $key,
        public SettingType $type,
        public mixed $default,
        public string $label,
        public string $help = '',
        public bool $isPublic = false,
        public array $rules = [],
        public array $options = [],
        public int|string|null $min = null,
        public int|string|null $max = null,
        public bool $nullable = false,
        public ?Closure $check = null,
        public bool $locked = false,
        public bool $oneWay = false,
    ) {
        if ($oneWay && $type !== SettingType::Bool) {
            throw new InvalidArgumentException("Penanda one_way hanya untuk setting bool [{$key}].");
        }
        if ((is_string($min) || is_string($max)) && ! $type->isDecimalString()) {
            throw new InvalidArgumentException("Batas desimal hanya untuk setting decimal/money [{$key}].");
        }
    }

    /** Nilai tersimpan → tipe PHP yang benar; enum berisi angka (mis. per halaman) menjadi int. */
    public function cast(mixed $value): mixed
    {
        if ($this->type === SettingType::Enum && $value !== null && $this->options !== [] && is_int($this->options[0])) {
            return (int) $value;
        }

        return $this->type->cast($value);
    }

    public function group(): string
    {
        return explode('.', $this->key, 2)[0];
    }

    /** Nama field di form/payload: titik diganti `__` agar tidak dianggap array oleh Laravel. */
    public function field(): string
    {
        return str_replace('.', '__', $this->key);
    }

    /**
     * Aturan validasi Laravel lengkap untuk nilai kunci ini.
     *
     * @return list<string|object>
     */
    public function validationRules(): array
    {
        $rules = [$this->nullable ? 'nullable' : 'required', ...$this->type->baseRules()];

        if ($this->type->isDecimalString()) {
            if ($this->min !== null || $this->max !== null) {
                $rules[] = $this->decimalBounds(...);
            }
        } else {
            if ($this->min !== null) {
                $rules[] = 'min:'.$this->min;
            }
            if ($this->max !== null) {
                $rules[] = 'max:'.$this->max;
            }
        }
        if ($this->type === SettingType::Enum && $this->options !== []) {
            $rules[] = 'in:'.implode(',', $this->options);
        }

        return [...$rules, ...$this->rules];
    }

    /**
     * Batas min/max untuk decimal & money tanpa float (dibandingkan sebagai string).
     *
     * @param  Closure(string): void  $fail
     */
    private function decimalBounds(string $attribute, mixed $value, Closure $fail): void
    {
        if (! DecimalString::isValid($value)) {
            return; // format sudah ditolak aturan regex tipe
        }
        if ($this->min !== null && DecimalString::compare($value, (string) $this->min) < 0) {
            $fail(":attribute minimal {$this->min}.");
        } elseif ($this->max !== null && DecimalString::compare($value, (string) $this->max) > 0) {
            $fail(":attribute maksimal {$this->max}.");
        }
    }
}
