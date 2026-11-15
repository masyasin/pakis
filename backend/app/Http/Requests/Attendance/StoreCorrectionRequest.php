<?php

namespace App\Http\Requests\Attendance;

use App\Domain\Attendance\Enums\CorrectionType;
use App\Domain\Attendance\Support\CorrectionAttachment;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Ajukan koreksi dari Pakis Pro (05 §5.7, multipart). Izin `attendance_corrections.create` dicek
 * middleware route; pegawai = pemilik token. Aturan tanggal (BR-069) & jendela shift dicek Action
 * agar kode galatnya sesuai kontrak (`CORRECTION_NOT_ALLOWED`, field jam).
 */
class StoreCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware `can:attendance_corrections.create`
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = (string) $this->input('type');

        return [
            'work_date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::enum(CorrectionType::class)],
            'proposed_check_in' => [in_array($type, ['check_in', 'both'], true) ? 'required' : 'nullable', 'date_format:H:i'],
            'proposed_check_out' => [in_array($type, ['check_out', 'both'], true) ? 'required' : 'nullable', 'date_format:H:i'],
            'reason_code' => ['required', 'string', Rule::exists('reference_items', 'code')
                ->where('group_code', ReferenceGroup::CorrectionReason->value)
                ->where('is_active', true)],
            'note' => ['required', 'string', 'min:10', 'max:500'],
            'attachment' => [
                'nullable',
                'file',
                'max:'.CorrectionAttachment::maxKb(),
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! $value instanceof UploadedFile || CorrectionAttachment::kind((string) $value->get()) === null) {
                        $fail('Lampiran harus foto JPEG/PNG atau PDF.');
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'proposed_check_in.required' => 'Jam masuk wajib diisi.',
            'proposed_check_out.required' => 'Jam pulang wajib diisi.',
            'proposed_check_in.date_format' => 'Jam masuk harus berformat HH:mm.',
            'proposed_check_out.date_format' => 'Jam pulang harus berformat HH:mm.',
            'reason_code.exists' => 'Alasan koreksi tidak dikenal.',
            'note.min' => 'Keterangan minimal 10 karakter.',
            'attachment.max' => 'Ukuran lampiran maksimal '.CorrectionAttachment::maxKb().' KB.',
        ];
    }

    /**
     * @return array{work_date: string, type: CorrectionType, proposed_check_in: ?string, proposed_check_out: ?string, reason_id: int, note: string}
     */
    public function correctionData(): array
    {
        $type = CorrectionType::from((string) $this->validated('type'));
        $in = $this->validated('proposed_check_in');
        $out = $this->validated('proposed_check_out');

        return [
            'work_date' => (string) $this->validated('work_date'),
            'type' => $type,
            'proposed_check_in' => $type->hasCheckIn() && is_string($in) ? $in : null,
            'proposed_check_out' => $type->hasCheckOut() && is_string($out) ? $out : null,
            'reason_id' => (int) ReferenceItem::query()
                ->forGroup(ReferenceGroup::CorrectionReason)
                ->where('code', (string) $this->validated('reason_code'))
                ->value('id'),
            'note' => trim((string) $this->validated('note')),
        ];
    }
}
