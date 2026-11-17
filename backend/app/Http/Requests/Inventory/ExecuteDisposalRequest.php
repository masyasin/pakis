<?php

namespace App\Http\Requests\Inventory;

use App\Domain\Inventory\Enums\DisposalMethod;
use App\Domain\Inventory\Models\DisposalReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * Laksanakan berita acara (BR-163, SEC-109): scan BA wajib, ≥ 1 foto, ≥ 2 saksi, tempat pelaksanaan.
 * Saksi HANYA `name`, `role`, `organization` — kunci lain (mis. `nik`) DITOLAK, bukan diabaikan, agar
 * NIK saksi tidak pernah tersimpan (CLAUDE.md §6). Jenis berkas diperiksa SecureUpload menurut isi.
 * Metode `return_to_principal` wajib supplier & alasan retur untuk retur draf (PA-69).
 */
class ExecuteDisposalRequest extends FormRequest
{
    private const WITNESS_KEYS = ['name', 'role', 'organization'];

    public function authorize(): bool
    {
        return true; // middleware `can:stock_disposals.manage`; status & scope dicek Policy
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $kb = (int) setting('upload.max_pdf_kb');

        return [
            'executed_location' => ['required', 'string', 'min:3', 'max:255'],
            'witnesses' => ['required', 'array', 'min:2', 'max:10'],
            'witnesses.*' => ['required', 'array'],
            'witnesses.*.name' => ['required', 'string', 'min:2', 'max:100'],
            'witnesses.*.role' => ['required', 'string', 'min:2', 'max:100'],
            'witnesses.*.organization' => ['required', 'string', 'min:2', 'max:150'],
            'document' => ['required', 'file', "max:{$kb}"],
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['required', 'file', 'max:'.(int) setting('upload.max_image_kb')],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'return_reason_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $v) {
            foreach ((array) $this->input('witnesses', []) as $i => $witness) {
                $unknown = is_array($witness) ? array_diff(array_keys($witness), self::WITNESS_KEYS) : [];
                if ($unknown !== []) {
                    $v->errors()->add("witnesses.{$i}", 'Data saksi hanya nama, jabatan, dan instansi (tanpa NIK).');
                }
            }
            $report = $this->route('report');
            if ($report instanceof DisposalReport && $report->method === DisposalMethod::ReturnToPrincipal) {
                if ($this->input('supplier_id') === null) {
                    $v->errors()->add('supplier_id', 'Pilih supplier/prinsipal tujuan retur.');
                }
                if ($this->input('return_reason_id') === null) {
                    $v->errors()->add('return_reason_id', 'Pilih alasan retur.');
                }
            }
        }];
    }

    /** @return array{executed_location: string, witnesses: list<array{name: string, role: string, organization: string}>, supplier_id: int|null, return_reason_id: int|null} */
    public function execution(): array
    {
        /** @var array<int, array{name: string, role: string, organization: string}> $witnesses */
        $witnesses = $this->validated('witnesses');

        return [
            'executed_location' => trim((string) $this->validated('executed_location')),
            'witnesses' => array_values(array_map(fn (array $w) => [
                'name' => trim($w['name']), 'role' => trim($w['role']), 'organization' => trim($w['organization']),
            ], $witnesses)),
            'supplier_id' => $this->validated('supplier_id') === null ? null : (int) $this->validated('supplier_id'),
            'return_reason_id' => $this->validated('return_reason_id') === null ? null : (int) $this->validated('return_reason_id'),
        ];
    }

    public function documentFile(): UploadedFile
    {
        $file = $this->file('document');
        abort_unless($file instanceof UploadedFile, 422);

        return $file;
    }

    /** @return list<UploadedFile> */
    public function photoFiles(): array
    {
        return array_values(array_filter((array) $this->file('photos', []), fn ($f) => $f instanceof UploadedFile));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'witnesses.min' => 'Minimal 2 saksi.',
            'witnesses.required' => 'Isi minimal 2 saksi.',
            'document.required' => 'Unggah scan berita acara bertanda tangan.',
            'photos.required' => 'Unggah minimal 1 foto pelaksanaan.',
            'executed_location.required' => 'Isi tempat pelaksanaan.',
        ];
    }
}
