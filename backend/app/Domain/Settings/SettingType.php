<?php

namespace App\Domain\Settings;

/** Tipe nilai setting (03 §4.2, 08 §2). Menentukan validasi dasar, konversi, dan field di form. */
enum SettingType: string
{
    case String = 'string';
    case Text = 'text';
    case Int = 'int';
    case Bool = 'bool';
    case Enum = 'enum';
    case MultiEnum = 'multi_enum';
    case Time = 'time';
    case Color = 'color';
    case Image = 'image';
    case Email = 'email';
    case Url = 'url';
    case EmailList = 'email_list';
    // PA-19 (T-067): angka desimal & rupiah disimpan sebagai string agar tidak dibulatkan float.
    case Decimal = 'decimal';
    case IntList = 'int_list';
    case Money = 'money';

    /**
     * Konversi nilai tersimpan (JSON) ke tipe PHP yang benar (BR-022).
     */
    public function cast(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::Int => (int) $value,
            self::Decimal => DecimalString::normalize(self::numericString($value)),
            self::Money => DecimalString::normalize(self::numericString($value), 2),
            self::IntList => array_values(array_map('intval', (array) $value)),
            self::Bool => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            self::MultiEnum, self::EmailList => array_values(array_map('strval', (array) $value)),
            self::Enum => is_int($value) ? $value : (string) $value,
            default => (string) $value,
        };
    }

    /**
     * Aturan validasi dasar per tipe; batas khusus ditambahkan SettingDefinition.
     *
     * @return list<string>
     */
    public function baseRules(): array
    {
        return match ($this) {
            self::String, self::Text => ['string'],
            self::Int => ['integer'],
            self::Bool => ['boolean'],
            self::Enum => [],
            self::MultiEnum, self::EmailList => ['array'],
            self::Time => ['date_format:H:i'],
            self::Color => ['regex:/^#[0-9A-Fa-f]{6}$/'],
            self::Image => [],
            self::Email => ['email:rfc'],
            self::Url => ['url:https'],
            // Batas min/max desimal dibandingkan sebagai string di SettingDefinition, bukan `min:`
            // Laravel (yang tanpa `numeric` menghitung panjang teks).
            self::Decimal => ['string', 'regex:/^-?\d{1,15}(\.\d{1,6})?$/'],
            // DECIMAL(18,2): maksimal 16 digit bulat, 2 digit sen.
            self::Money => ['string', 'regex:/^-?\d{1,16}(\.\d{1,2})?$/'],
            self::IntList => ['array'],
        };
    }

    /**
     * Aturan per butir untuk tipe daftar (`<field>.*`).
     *
     * @return list<string>
     */
    public function itemRules(): array
    {
        return match ($this) {
            self::IntList => ['integer', 'distinct'],
            default => [],
        };
    }

    /** Tipe angka desimal berbentuk string (batasnya dibandingkan lewat DecimalString). */
    public function isDecimalString(): bool
    {
        return $this === self::Decimal || $this === self::Money;
    }

    /** Angka dari JSON (int/float) → string; float dikonversi PHP ke representasi terpendek. */
    private static function numericString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
