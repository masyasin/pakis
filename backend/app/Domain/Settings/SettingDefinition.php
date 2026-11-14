<?php

namespace App\Domain\Settings;

use Closure;

/**
 * Definisi satu kunci setting (03 §4.2): tipe, default, validasi, label, dan penanda publik.
 * Nilai di sini adalah sumber kebenaran kode; dicocokkan dengan 08 §2 oleh SettingRegistryTest.
 */
final readonly class SettingDefinition
{
    /**
     * @param  list<string|object>  $rules  aturan tambahan selain aturan dasar tipe
     * @param  list<string|int>  $options  untuk enum / multi_enum
     * @param  (Closure(mixed, array<string, mixed>): ?string)|null  $check  validasi kustom:
     *                                                                       terima nilai & semua nilai grup, kembalikan pesan galat atau null
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
        public ?int $min = null,
        public ?int $max = null,
        public bool $nullable = false,
        public ?Closure $check = null,
    ) {}

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

        if ($this->min !== null) {
            $rules[] = 'min:'.$this->min;
        }
        if ($this->max !== null) {
            $rules[] = 'max:'.$this->max;
        }
        if ($this->type === SettingType::Enum && $this->options !== []) {
            $rules[] = 'in:'.implode(',', $this->options);
        }

        return [...$rules, ...$this->rules];
    }
}
