<?php

namespace App\Http\Requests\Inventory;

use App\Support\Money\Qty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Isi satu baris lembar hitung (PUT `admin.stock-opnames.lines.count`, 15 §5): `mode` = `count`
 * (hitung pertama), `recount` (hitung ulang orang lain), atau `reason` (alasan selisih saat tinjau).
 * Qty satuan dasar, format id-ID dinormalkan lewat Qty. Foto opsional (SEC-109, SecureUpload).
 */
class OpnameLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:stock_opnames.manage`; status & scope dicek Policy
    }

    protected function prepareForValidation(): void
    {
        $qty = $this->input('qty');
        if (is_string($qty) && preg_match('/^[\d.,\s]+$/', trim($qty)) === 1) {
            $this->merge(['qty' => (string) Qty::fromInput($qty)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['count', 'recount', 'reason'])],
            'qty' => ['required_unless:mode,reason', 'nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,3})?$/'],
            'variance_reason_id' => ['required_if:mode,reason', 'nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'file', 'max:'.(int) setting('upload.max_image_kb')],
        ];
    }

    public function qty(): Qty
    {
        return Qty::of((string) $this->validated('qty'));
    }

    public function note(): ?string
    {
        $note = trim((string) $this->validated('note', ''));

        return $note === '' ? null : $note;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'qty.required_unless' => 'Isi jumlah yang dihitung.',
            'qty.regex' => 'Jumlah berupa angka, maks 3 desimal.',
            'variance_reason_id.required_if' => 'Pilih alasan selisih.',
        ];
    }
}
