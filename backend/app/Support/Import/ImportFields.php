<?php

namespace App\Support\Import;

/**
 * Daftar field sistem satu tipe impor (BR-170): label, wajib, petunjuk, dan sinonim judul kolom.
 * Sinonim ditulis dalam bentuk ternormalisasi (`normalizeHeader`): huruf kecil, isi kurung dibuang,
 * tanda baca → spasi. Satu sumber untuk saran pemetaan, deteksi baris judul, templat, dan pesan.
 */
final class ImportFields
{
    /**
     * @param  array<string, array{label: string, required: bool, synonyms: list<string>, hint: string}>  $fields
     */
    public function __construct(public readonly array $fields) {}

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->fields);
    }

    /** @return list<string> */
    public function required(): array
    {
        return array_keys(array_filter($this->fields, fn (array $f) => $f['required']));
    }

    public function has(string $field): bool
    {
        return isset($this->fields[$field]);
    }

    public function isRequired(string $field): bool
    {
        return $this->fields[$field]['required'] ?? false;
    }

    public function label(string $field): string
    {
        return $this->fields[$field]['label'] ?? $field;
    }

    /** Label untuk pesan galat: `_row` = galat seluruh baris. */
    public function errorLabel(string $field): string
    {
        return $field === '_row' ? 'Baris' : $this->label($field);
    }

    /** @return list<array{key: string, label: string, required: bool, hint: string}> */
    public function forUi(): array
    {
        return array_map(
            fn (string $key) => ['key' => $key, 'label' => $this->fields[$key]['label'], 'required' => $this->fields[$key]['required'], 'hint' => $this->fields[$key]['hint']],
            $this->keys(),
        );
    }

    /**
     * "No. HP (WA)*" → "no hp"; "NAMA  PEGAWAI" → "nama pegawai". Kurung dibuang karena biasanya
     * berisi petunjuk format, bukan nama kolom.
     */
    public static function normalizeHeader(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        $text = mb_strtolower(trim((string) $value));
        $text = (string) preg_replace('/\([^)]*\)|\[[^]]*\]/u', ' ', $text);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim($text);
    }

    /** Field untuk judul kolom ini menurut sinonim, atau null. */
    public function fieldForHeader(mixed $header): ?string
    {
        $normalized = self::normalizeHeader($header);
        if ($normalized === '') {
            return null;
        }

        foreach ($this->fields as $key => $field) {
            if (in_array($normalized, $field['synonyms'], true)) {
                return $key;
            }
        }

        return null;
    }
}
