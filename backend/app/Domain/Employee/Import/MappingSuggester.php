<?php

namespace App\Domain\Employee\Import;

use App\Domain\Employee\Models\ImportBatch;
use App\Domain\Employee\Models\ImportMappingProfile;

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
        return hash('sha256', implode("\x1F", array_map(EmployeeImportFields::normalizeHeader(...), $headers)));
    }

    /**
     * @param  list<string>  $headers
     * @return array{columns: array<int, string>, profile: ImportMappingProfile|null}
     */
    public static function suggest(array $headers): array
    {
        $profile = ImportMappingProfile::query()
            ->where('type', ImportBatch::TYPE_EMPLOYEES)
            ->where('header_signature', self::signature($headers))
            ->latest('updated_at')
            ->first();

        if ($profile !== null) {
            return ['columns' => self::fromProfile($profile, $headers), 'profile' => $profile];
        }

        $columns = [];
        foreach ($headers as $index => $header) {
            $field = EmployeeImportFields::fieldForHeader($header);
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
    public static function fromProfile(ImportMappingProfile $profile, array $headers): array
    {
        $byHeader = [];
        foreach ($profile->mapping as $header => $field) {
            $byHeader[EmployeeImportFields::normalizeHeader($header)] = $field;
        }

        $columns = [];
        foreach ($headers as $index => $header) {
            $field = $byHeader[EmployeeImportFields::normalizeHeader($header)] ?? null;
            if ($field !== null && isset(EmployeeImportFields::FIELDS[$field]) && ! in_array($field, $columns, true)) {
                $columns[$index] = $field;
            }
        }

        return $columns;
    }
}
