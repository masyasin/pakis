<?php

namespace App\Domain\Employee\Import;

use App\Domain\Employee\Actions\ApplyImportRow;
use App\Models\User;
use App\Support\Import\AppliedRow;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportFields;
use App\Support\Import\ImportFile;
use App\Support\Import\ImportOrder;
use App\Support\Import\ImportPhase;
use App\Support\Import\ImportType;
use App\Support\Import\NormalizedRow;
use App\Support\Import\ValidatedRow;
use InvalidArgumentException;

/**
 * Impor pegawai Fase 0 (BR-054, T-027/T-028) sebagai implementasi kontrak ImportType (T-085A).
 * Perilakunya sama dengan sebelum kerangka diekstrak: rute `impor/pegawai…`, izin
 * `employees.import`, pesan, kunci baris (NIK → no. pegawai → nama+tgl lahir), atasan di berkas
 * yang sama diproses lebih dulu, dan pegawai lama tidak dimutasi.
 */
final class EmployeeImportType implements ImportType
{
    public const KEY = 'employees';

    /** Kunci galat Action pegawai → field impor, agar sel yang tepat ditandai merah. */
    private const ERROR_FIELDS = [
        'position_id' => 'position', 'location_id' => 'location', 'supervisor_employee_id' => 'supervisor_number',
        'employment_status_id' => 'employment_status', 'start_date' => 'join_date',
    ];

    public function __construct(private readonly ApplyImportRow $apply) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Pegawai';
    }

    public function routeSlug(): ?string
    {
        return null; // rute sendiri sejak Fase 0: `impor/pegawai…` (admin.imports.*)
    }

    public function permission(): string
    {
        return 'employees.import';
    }

    public function updatePermission(): string
    {
        return 'employees.update';
    }

    public function fields(): ImportFields
    {
        return EmployeeImportFields::definition();
    }

    /**
     * Validasi: nomor pegawai di berkas (hanya bila kolom atasan & nomor dipetakan) agar atasan
     * yang ada di berkas yang sama dikenali. Proses: semua nomor pegawai di berkas (atasan dari
     * berkas yang sama sah walau barisnya galat; pesannya diberikan ApplyImportRow).
     */
    public function context(User $actor, ImportBatch $batch, ImportPhase $phase): EmployeeImportContext
    {
        $columns = $batch->columnMapping();
        $numbers = [];
        $scan = $phase === ImportPhase::Process
            || (in_array('supervisor_number', $columns, true) && in_array('employee_number', $columns, true));

        if ($scan && in_array('employee_number', $columns, true)) {
            $numberColumn = (int) array_search('employee_number', $columns, true);
            foreach (ImportFile::dataRows($batch) as $cells) {
                $number = RowNormalizer::code($cells[$numberColumn] ?? null);
                if ($number !== null) {
                    $numbers[] = $number;
                }
            }
        }

        return new EmployeeImportContext(new RowValidator($actor, $numbers));
    }

    public function normalize(array $raw, object $context): NormalizedRow
    {
        return self::own($context)->validator->normalize($raw);
    }

    public function validate(NormalizedRow $row, object $context): ValidatedRow
    {
        return self::own($context)->validator->check($row);
    }

    public function rowKey(ValidatedRow $row): ?string
    {
        return RowValidator::key($row);
    }

    public function describeKey(string $key): string
    {
        return RowKey::describe($key);
    }

    /**
     * Atasan (nomor pegawai di berkas) sebelum bawahannya. Tiap baris punya paling banyak satu
     * atasan, jadi graf berupa rantai/pohon; sisa setelah Kahn = baris pada lingkaran atau yang
     * menggantung pada lingkaran. Baris lingkaran dulu (ditolak), lalu yang menggantung padanya.
     */
    public function processOrder(array $raws): ImportOrder
    {
        $byNumber = [];
        foreach ($raws as $row => $raw) {
            $code = RowNormalizer::code($raw['employee_number'] ?? null);
            if ($code !== null) {
                $byNumber[mb_strtolower($code)] ??= $row;
            }
        }

        $parent = [];
        $children = [];
        foreach ($raws as $row => $raw) {
            $supervisor = RowNormalizer::code($raw['supervisor_number'] ?? null);
            $boss = $supervisor !== null ? ($byNumber[mb_strtolower($supervisor)] ?? null) : null;
            if ($boss !== null && $boss !== $row) {
                $parent[$row] = $boss;
                $children[$boss][] = $row;
            }
        }

        $order = [];
        $queue = array_values(array_filter(array_keys($raws), fn (int $row) => ! isset($parent[$row])));
        while ($queue !== []) {
            $row = array_shift($queue);
            $order[] = $row;
            foreach ($children[$row] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        $cyclic = [];
        $left = array_values(array_diff(array_keys($raws), $order));
        foreach ($left as $row) {
            // Ikuti rantai atasan; bila kembali ke baris ini, baris ini bagian lingkaran.
            $seen = [];
            for ($at = $parent[$row] ?? null; $at !== null && ! isset($seen[$at]); $at = $parent[$at] ?? null) {
                if ($at === $row) {
                    $cyclic[$row] = ['supervisor_number' => ['Rantai atasan di berkas ini melingkar (mis. A atasan B dan B atasan A).']];
                    break;
                }
                $seen[$at] = true;
            }
        }

        usort($left, fn (int $a, int $b) => [! isset($cyclic[$a]), $a] <=> [! isset($cyclic[$b]), $b]);

        return new ImportOrder([...$order, ...$left], $cyclic);
    }

    public function apply(User $actor, ValidatedRow $row, array $raw, bool $updateExisting, object $context): AppliedRow
    {
        $result = $this->apply->handle($actor, $row, RowValidator::key($row), $raw, $updateExisting, self::own($context)->resolved);

        return new AppliedRow($result['status'], ['employee_id' => $result['employee_id'], 'warnings' => $result['warnings']]);
    }

    public function committed(object $context, array $raw, AppliedRow $row): void
    {
        $code = RowNormalizer::code($raw['employee_number'] ?? null);
        $id = $row->data['employee_id'] ?? null;
        if ($code !== null && is_int($id)) {
            self::own($context)->resolved[mb_strtolower($code)] = $id;
        }
    }

    public function errorField(string $key): string
    {
        $field = str_starts_with($key, 'assignment.') ? substr($key, strlen('assignment.')) : $key;

        return self::ERROR_FIELDS[$field] ?? $field;
    }

    private static function own(object $context): EmployeeImportContext
    {
        if (! $context instanceof EmployeeImportContext) {
            throw new InvalidArgumentException('Konteks impor pegawai tidak sah.');
        }

        return $context;
    }
}
