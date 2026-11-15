<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Setujui / tolak koreksi (05 §5.8). Catatan opsional saat setuju, WAJIB 5–500 saat tolak (BR-069).
 * Wewenang 06 §6 dicek binding + Action (kode galat khusus SEC-031).
 */
class DecideCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:attendance_corrections.approve`
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rejecting = $this->route()?->getName() === 'api.v1.approvals.attendance-corrections.reject';

        return [
            'note' => $rejecting ? ['required', 'string', 'min:5', 'max:500'] : ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'note.required' => 'Alasan penolakan wajib diisi.',
            'note.min' => 'Alasan penolakan minimal 5 karakter.',
        ];
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
