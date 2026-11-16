<?php

namespace App\Support\Import;

/**
 * Saran pemetaan kolom (BR-054): profil yang `header_signature`-nya sama dipilih otomatis; bila
 * tidak ada, dari sinonim judul kolom. Satu field hanya untuk satu kolom (kolom pertama menang).
 */
final class MappingSuggester
{
    /**
     * sha256 dari judul ternormalisasi berurutan: "Nama Lengkap" dan "NAMA  LENGKAP" bertanda tangan
     * sama, sedangkan urutan kolom berbeda = berkas berbeda.
     *
     * @param  list<string>  $headers
     */
    public static function signature(array $headers): string
    {
        return hash('sha256', implode("\x1F", array_map(ImportFields::normalizeHeader(...), $headers)));
    }

    /**
     * @param  list<string>  $headers
     * @return array{columns: array<int, string>, profile: ImportMappingProfile|null}
     */
    public static function suggest(ImportType $type, array $headers): array
    {
        $profile = ImportMappingProfile::query()
            ->where('type', $type->key())
            ->where('header_signature', self::signature($headers))
            ->latest('updated_at')
            ->first();

        if ($profile !== null) {
            return ['columns' => self::fromProfile($type->fields(), $profile, $headers), 'profile' => $profile];
        }

        $columns = [];
        foreach ($headers as $index => $header) {
            $field = $type->fields()->fieldForHeader($header);
            if ($field !== null && ! in_array($field, $columns, true)) {
                $columns[$index] = $field;
            }
        }

        return ['columns' => $columns, 'profile' => null];
    }

    /**
     * Profil disimpan per judul kolom (04 §2.10); dicocokkan lewat judul ternormalisasi.
     *
     * @param  list<string>  $headers
     * @return array<int, string>
     */
    public static function fromProfile(ImportFields $fields, ImportMappingProfile $profile, array $headers): array
    {
        $byHeader = [];
        foreach ($profile->mapping as $header => $field) {
            $byHeader[ImportFields::normalizeHeader($header)] = $field;
        }

        $columns = [];
        foreach ($headers as $index => $header) {
            $field = $byHeader[ImportFields::normalizeHeader($header)] ?? null;
            if ($field !== null && $fields->has($field) && ! in_array($field, $columns, true)) {
                $columns[$index] = $field;
            }
        }

        return $columns;
    }
}
