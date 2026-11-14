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
        };
    }
}
