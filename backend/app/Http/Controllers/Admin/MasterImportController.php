<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\ImportUploadRequest;
use App\Models\User;
use App\Support\Import\Actions\StoreImportUpload;
use App\Support\Import\ImportRegistry;
use App\Support\Import\ImportTemplateFile;
use App\Support\Import\ImportType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Langkah 1 impor untuk tipe selain pegawai (`admin.master-imports.*`, `/impor/{type}`, BR-170,
 * T-085A). `{type}` dibatasi pola slug terdaftar di rute; izin = izin tipe itu
 * (`ImportType::permission()`). Langkah berikutnya memakai rute batch bersama `admin.imports.*`.
 * Izin payung `master_imports.manage` (15 §5) dipasang T-085 lewat `ImportRegistry::allows()`.
 */
class MasterImportController extends Controller
{
    public function create(Request $request, string $type): Response
    {
        $import = self::type($request, $type);

        return Inertia::render('imports/create', [
            'limits' => ['max_file_mb' => (int) setting('import.max_file_mb'), 'max_rows' => ImportRegistry::maxRows($import->key())],
            'fields' => $import->fields()->forUi(),
            'importType' => [
                'label' => $import->label(),
                'description' => 'Unggah daftar '.mb_strtolower($import->label()).' dari Excel atau CSV. Kolom boleh berurutan bebas dan judul tabel boleh ada di atasnya.',
                'store_url' => route('admin.master-imports.store', $type, false),
                'template_url' => route('admin.master-imports.template', $type, false),
            ],
        ]);
    }

    public function template(Request $request, string $type, ImportTemplateFile $template): BinaryFileResponse
    {
        $import = self::type($request, $type);
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $template->write($import, $path);

        return response()->download($path, 'templat_'.$type.'.xlsx')->deleteFileAfterSend();
    }

    public function store(ImportUploadRequest $request, string $type, StoreImportUpload $action): RedirectResponse
    {
        $import = self::type($request, $type);
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return to_route('admin.imports.mapping', $action->handle($actor, $import, $file));
    }

    /** Slug → tipe terdaftar (404 bila tidak ada), lalu cek izin tipe (403). */
    private static function type(Request $request, string $slug): ImportType
    {
        $import = ImportRegistry::forSlug($slug);
        abort_if($import === null, 404);
        $user = $request->user();
        abort_unless($user instanceof User && ImportRegistry::allows($user, $import), 403);

        return $import;
    }
}
