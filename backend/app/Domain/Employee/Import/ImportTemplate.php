<?php

namespace App\Domain\Employee\Import;

use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Position;
use App\Domain\Organization\Support\LocationScope;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Export\FormulaEscaper;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Templat impor pegawai (05 `admin.imports.template`). Dibangun saat diunduh dari daftar field
 * yang sama dengan pemetaan, jadi tidak pernah ketinggalan dari kode. Sheet "Petunjuk" memuat
 * kode status kerja, lokasi (sesuai scope pengunduh), dan jabatan yang berlaku saat ini.
 */
final class ImportTemplate
{
    /** Kolom yang wajib diketik sebagai teks agar 0 di depan/16 digit tidak berubah. */
    private const TEXT_FIELDS = ['employee_number', 'nik', 'phone', 'supervisor_number'];

    public function write(User $user, string $absolutePath): void
    {
        $writer = new Writer;
        $writer->openToFile($absolutePath);
        $bold = (new Style)->withFontBold(true);
        $text = (new Style)->withFormat('@');

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Data Pegawai');
            $fields = EmployeeImportFields::FIELDS;
            $sheet->setColumnWidthForRange(22, 1, count($fields));

            $writer->addRow(Row::fromValuesWithStyle(
                array_map(fn (array $f) => $f['label'].($f['required'] ? '*' : ''), array_values($fields)),
                $bold,
            ));

            // Satu baris contoh (data dummy): hapus sebelum mengisi.
            $example = $this->example();
            $values = [];
            $styles = [];
            foreach (array_keys($fields) as $i => $key) {
                $values[$i] = $example[$key] ?? '';
                if (in_array($key, self::TEXT_FIELDS, true)) {
                    $styles[$i] = $text;
                }
            }
            $writer->addRow(Row::fromValuesWithStyles($values, $styles));

            $guide = $writer->addNewSheetAndMakeItCurrent();
            $guide->setName('Petunjuk');
            $guide->setColumnWidth(26, 1);
            $guide->setColumnWidth(10, 2);
            $guide->setColumnWidth(60, 3);
            $writer->addRow(Row::fromValuesWithStyle(['Kolom', 'Wajib', 'Keterangan'], $bold));
            foreach ($fields as $field) {
                $writer->addRow(Row::fromValues([$field['label'], $field['required'] ? 'Ya' : '', $field['hint']]));
            }
            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues(['Baris judul boleh di baris mana pun dalam 10 baris teratas. Kolom boleh diurutkan bebas; pemetaan dicocokkan saat unggah.']));
            $writer->addRow(Row::fromValues(['Format kolom NIK, No. HP, dan No. Pegawai sebagai Teks sebelum mengetik, agar angka tidak berubah.']));

            foreach ($this->references($user) as $title => $rows) {
                $writer->addRow(Row::fromValues(['']));
                $writer->addRow(Row::fromValuesWithStyle([$title, 'Kode', 'Nama'], $bold));
                foreach ($rows as [$code, $name]) {
                    $writer->addRow(Row::fromValues(['', FormulaEscaper::escape($code), FormulaEscaper::escape($name)]));
                }
            }
        } finally {
            $writer->close();
        }
    }

    /** @return array<string, string> */
    private function example(): array
    {
        return [
            'full_name' => 'Contoh Pegawai (hapus baris ini)',
            'gender' => 'L',
            'join_date' => '01/02/2024',
            'employment_status' => (string) ReferenceItem::query()->forGroup(ReferenceGroup::EmploymentStatus)->where('is_active', true)->orderBy('sort_order')->value('name'),
            'location' => (string) Location::query()->where('is_active', true)->orderBy('code')->value('code'),
            'position' => (string) Position::query()->where('is_active', true)->orderBy('code')->value('code'),
            'birth_date' => '15/08/1995',
            'phone' => '0812-0000-0000',
            'email' => 'contoh@pakis-demo.test',
        ];
    }

    /** @return array<string, list<array{0: string, 1: string}>> */
    private function references(User $user): array
    {
        $locations = Location::query()->where('is_active', true)->orderBy('code');
        app(LocationScope::class)->apply($locations, $user, 'locations.id');

        $pairs = fn ($query) => $query->get(['code', 'name'])
            ->map(fn ($m) => [(string) $m->getAttribute('code'), (string) $m->getAttribute('name')])->values()->all();

        return [
            'Status kerja' => $pairs(ReferenceItem::query()->forGroup(ReferenceGroup::EmploymentStatus)->where('is_active', true)->orderBy('sort_order')),
            'Lokasi' => $pairs($locations),
            'Jabatan' => $pairs(Position::query()->where('is_active', true)->orderBy('code')),
        ];
    }
}
