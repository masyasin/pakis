<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Employee\Actions\CancelImport;
use App\Domain\Employee\Actions\ProcessImportBatch;
use App\Domain\Employee\Actions\SaveImportMapping;
use App\Domain\Employee\Actions\StoreImportUpload;
use App\Domain\Employee\Enums\ImportStatus;
use App\Domain\Employee\Import\EmployeeImportFields;
use App\Domain\Employee\Import\ImportErrorFile;
use App\Domain\Employee\Import\ImportFile;
use App\Domain\Employee\Import\ImportTemplate;
use App\Domain\Employee\Import\MappingSuggester;
use App\Domain\Employee\Import\RowNormalizer;
use App\Domain\Employee\Listings\ImportBatchListing;
use App\Domain\Employee\Listings\ImportRowListing;
use App\Domain\Employee\Models\ImportBatch;
use App\Domain\Employee\Models\ImportRow;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\ImportMappingRequest;
use App\Http\Requests\Employee\ImportProcessRequest;
use App\Http\Requests\Employee\ImportUploadRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Time\DisplayTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Impor pegawai (05 §4.7, FR-028, BR-054): unggah → pemetaan → validasi (T-027) → proses &
 * laporan + unduh galat (T-028). Semua route `can:employees.import`; batch di luar visibilitas
 * pengguna → 404 (route binding).
 */
class ImportController extends Controller
{
    /** Jumlah contoh nilai per kolom di langkah pemetaan. */
    private const SAMPLE_ROWS = 3;

    #[UsesListing(ImportBatchListing::class)]
    public function index(Request $request): Response
    {
        $result = ListingQuery::apply(ImportBatchListing::query(self::actor($request)), new ImportBatchListing, $request)
            ->through(fn (ImportBatch $b) => [
                'id' => $b->id,
                'original_name' => $b->original_name,
                'status' => $b->status->value,
                'status_label' => $b->status->label(),
                'total_rows' => $b->total_rows,
                'valid_rows' => $b->valid_rows,
                'error_rows' => $b->error_rows,
                'created_rows' => $b->created_rows,
                'updated_rows' => $b->updated_rows,
                'created_at' => $b->created_at?->toIso8601String(),
                'creator' => $b->creator?->name,
            ]);

        return Inertia::render('imports/index', $result->toInertia());
    }

    public function create(): Response
    {
        return Inertia::render('imports/create', [
            'limits' => ['max_file_mb' => (int) setting('import.max_file_mb'), 'max_rows' => (int) setting('import.max_rows')],
            'fields' => self::fields(),
        ]);
    }

    public function store(ImportUploadRequest $request, StoreImportUpload $action): RedirectResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $batch = $action->handle(self::actor($request), $file);

        return to_route('admin.imports.mapping', $batch);
    }

    public function template(Request $request, ImportTemplate $template): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $template->write(self::actor($request), $path);

        return response()->download($path, 'templat_pegawai.xlsx')->deleteFileAfterSend();
    }

    public function show(ImportBatch $batch): RedirectResponse
    {
        return $batch->status === ImportStatus::Uploaded
            ? to_route('admin.imports.mapping', $batch)
            : to_route('admin.imports.rows.index', $batch);
    }

    public function mapping(ImportBatch $batch): Response|RedirectResponse
    {
        if (! $batch->status->canRemap()) {
            return to_route('admin.imports.rows.index', $batch);
        }

        $headers = $batch->headers();
        $suggestion = MappingSuggester::suggest($headers);
        $saved = $batch->columnMapping();

        return Inertia::render('imports/mapping', [
            'batch' => self::summary($batch),
            'columns' => array_map(fn (string $header, int $index) => [
                'index' => $index,
                'header' => $header,
            ], $headers, array_keys($headers)),
            'samples' => self::samples($batch, count($headers)),
            'mapping' => (object) ($saved !== [] ? $saved : $suggestion['columns']),
            'profile' => $suggestion['profile'] !== null ? ['id' => $suggestion['profile']->id, 'name' => $suggestion['profile']->name] : null,
            'fields' => self::fields(),
        ]);
    }

    public function updateMapping(ImportMappingRequest $request, ImportBatch $batch, SaveImportMapping $action): RedirectResponse
    {
        $batch = $action->handle(self::actor($request), $batch, $request->columns(), $request->profileName());

        if ($request->profileName() !== null) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Pemetaan disimpan sebagai profil "'.$request->profileName().'".']);
        }

        return to_route('admin.imports.rows.index', $batch);
    }

    #[UsesListing(ImportRowListing::class)]
    public function rows(Request $request, ImportBatch $batch): Response|RedirectResponse
    {
        if ($batch->status === ImportStatus::Uploaded) {
            return to_route('admin.imports.mapping', $batch);
        }

        $listing = new ImportRowListing(errorsFirst: $batch->error_rows > 0);
        $result = ListingQuery::apply(ImportRow::query()->where('import_batch_id', $batch->id), $listing, $request)
            ->through(fn (ImportRow $r) => [
                'id' => $r->id,
                'row_number' => $r->row_number,
                'status' => $r->status->value,
                'data' => $r->data,
                'errors' => self::errorList($r->errors ?? []),
                'warnings' => array_values(array_filter((array) ($r->data['warnings'] ?? []), is_string(...))),
                'employee_id' => is_int($r->data['employee_id'] ?? null) ? $r->data['employee_id'] : null,
            ]);

        $mapped = array_values(array_intersect(EmployeeImportFields::keys(), $batch->columnMapping()));

        return Inertia::render('imports/rows', [
            ...$result->toInertia(),
            'batch' => self::summary($batch),
            'mappedFields' => array_map(fn (string $f) => ['key' => $f, 'label' => EmployeeImportFields::label($f)], $mapped),
            'can' => [
                'remap' => $batch->status->canRemap(),
                'cancel' => $batch->status->canCancel(),
                'process' => $batch->status->canProcess() && $batch->valid_rows > 0 && Storage::disk(ImportFile::DISK)->exists($batch->stored_path),
                'download_errors' => $batch->status->hasErrorReport() && $batch->error_rows > 0,
                'view_employee' => $request->user()?->can('employees.view') ?? false,
            ],
        ]);
    }

    public function process(ImportProcessRequest $request, ImportBatch $batch, ProcessImportBatch $action): RedirectResponse
    {
        $batch = $action->start(self::actor($request), $batch, $request->updateExisting());

        Inertia::flash('toast', match ($batch->status) {
            ImportStatus::Completed => ['type' => 'success', 'message' => sprintf('Impor selesai: %d dibuat, %d diperbarui, %d dilewati, %d galat.', $batch->created_rows, $batch->updated_rows, $batch->skipped_rows, $batch->error_rows)],
            ImportStatus::Failed => ['type' => 'error', 'message' => 'Impor berhenti di tengah jalan. Baris yang sudah diproses tetap tersimpan; unggah ulang berkasnya untuk melanjutkan.'],
            default => ['type' => 'success', 'message' => 'Impor sedang diproses di latar belakang. Anda akan mendapat notifikasi saat selesai.'],
        });

        return to_route('admin.imports.rows.index', $batch);
    }

    /**
     * Unduh baris galat (.xlsx + kolom pesan). Tercatat di audit seperti ekspor lain (SEC-051).
     */
    public function errors(Request $request, ImportBatch $batch, ImportErrorFile $file, AuditLogger $audit): BinaryFileResponse
    {
        abort_unless($batch->status->hasErrorReport() && $batch->error_rows > 0, 404);

        $path = tempnam(sys_get_temp_dir(), 'galat').'.xlsx';
        $count = $file->write($batch, $path);
        $audit->record('export.created', $batch, null, ['listing' => 'import_rows', 'rows' => $count, 'format' => 'xlsx']);

        $name = 'galat_'.pathinfo($batch->original_name, PATHINFO_FILENAME).'.xlsx';

        return response()->download($path, $name)->deleteFileAfterSend();
    }

    public function cancel(Request $request, ImportBatch $batch, CancelImport $action): RedirectResponse
    {
        $action->handle(self::actor($request), $batch);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Impor \"{$batch->original_name}\" dibatalkan. Berkasnya sudah dihapus."]);

        return to_route('admin.imports.index');
    }

    /** @return array<string, mixed> */
    private static function summary(ImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'original_name' => $batch->original_name,
            'status' => $batch->status->value,
            'status_label' => $batch->status->label(),
            'header_row' => $batch->headerRow(),
            'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->valid_rows,
            'error_rows' => $batch->error_rows,
            'created_rows' => $batch->created_rows,
            'updated_rows' => $batch->updated_rows,
            'skipped_rows' => $batch->skipped_rows,
            'update_existing' => ProcessImportBatch::updateExisting($batch),
            'completed_at_local' => $batch->completed_at !== null ? DisplayTime::toLocal($batch->completed_at)->format('Y-m-d H:i') : null,
            'failure' => $batch->mapping['failure'] ?? null,
            'file_available' => Storage::disk(ImportFile::DISK)->exists($batch->stored_path),
            'created_at' => $batch->created_at?->toIso8601String(),
            'created_at_local' => $batch->created_at !== null ? DisplayTime::toLocal($batch->created_at)->format('Y-m-d H:i') : null,
        ];
    }

    /** @return list<array{key: string, label: string, required: bool, hint: string}> */
    private static function fields(): array
    {
        return array_map(
            fn (string $key) => ['key' => $key, 'label' => EmployeeImportFields::FIELDS[$key]['label'], 'required' => EmployeeImportFields::FIELDS[$key]['required'], 'hint' => EmployeeImportFields::FIELDS[$key]['hint']],
            EmployeeImportFields::keys(),
        );
    }

    /**
     * Beberapa nilai contoh per kolom untuk membantu memetakan. Deretan ≥ 15 digit (kemungkinan NIK)
     * dimasking: pemetaan belum diketahui, jadi kolom NIK belum bisa dikenali (SEC-010).
     *
     * @return list<list<string>> per kolom
     */
    private static function samples(ImportBatch $batch, int $columns): array
    {
        $samples = array_fill(0, $columns, []);
        if (! Storage::disk(ImportFile::DISK)->exists($batch->stored_path)) {
            return $samples;
        }

        $taken = 0;
        foreach (ImportFile::dataRows($batch) as $cells) {
            for ($i = 0; $i < $columns; $i++) {
                $text = RowNormalizer::text($cells[$i] ?? null);
                if ($text !== null) {
                    $samples[$i][] = mb_strimwidth(self::maskLongDigits($text), 0, 60, '…');
                }
            }
            if (++$taken >= self::SAMPLE_ROWS) {
                break;
            }
        }

        return $samples;
    }

    /**
     * Deretan ≥ 15 digit, termasuk yang bersela spasi/titik/strip seperti yang diterima parser NIK
     * ("3319 0101 0101 0001"), dimasking SEBELUM teks dipotong agar potongan NIK tidak lolos.
     */
    private static function maskLongDigits(string $text): string
    {
        return (string) preg_replace_callback('/\d(?:[\s.\-]*\d){14,}/', function (array $m): string {
            $digits = (string) preg_replace('/\D/', '', $m[0]);

            return substr($digits, 0, 4).str_repeat('*', strlen($digits) - 8).substr($digits, -4);
        }, $text);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<array{field: string, label: string, message: string}>
     */
    private static function errorList(array $errors): array
    {
        $list = [];
        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                $list[] = ['field' => $field, 'label' => $field === '_row' ? 'Baris' : EmployeeImportFields::label($field), 'message' => $message];
            }
        }

        return $list;
    }

    private static function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
