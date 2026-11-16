<?php

namespace App\Http\Requests\Approval;

use App\Domain\Approval\Policies\ApprovalDelegationPolicy;
use App\Domain\Approval\Support\ApprovableRegistry;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Isian delegasi (BR-153(7)). Pemberi = pengguna sendiri; pemberi lain hanya untuk pemegang
 * `approvals.configure` (15 §5). Tanggal lokal, mulai paling awal hari ini (delegasi berlaku surut
 * tidak bermakna: hak dihitung saat keputusan). Penerima akun aktif, bukan pemberi.
 */
class ApprovalDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && ApprovalDelegationPolicy::mayCreateFor($user, $this->delegatorId());
    }

    protected function prepareForValidation(): void
    {
        $types = $this->input('document_types');
        $this->merge([
            'document_types' => $types === [] || $types === '' ? null : $types,
            'reason' => is_string($this->input('reason')) ? trim($this->input('reason')) : $this->input('reason'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $today = DisplayTime::toLocal(now())->toDateString();

        return [
            'delegator_user_id' => ['nullable', 'integer'],
            'delegate_user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'document_types' => ['nullable', 'array', 'min:1'],
            'document_types.*' => ['string', 'distinct', Rule::in(ApprovableRegistry::types())],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'delegate_user_id.required' => 'Pilih penerima delegasi.',
            'delegate_user_id.exists' => 'Penerima delegasi harus akun aktif.',
            'start_date.after_or_equal' => 'Tanggal mulai paling awal hari ini.',
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'reason.required' => 'Alasan delegasi wajib diisi.',
            'reason.min' => 'Alasan delegasi minimal 5 karakter.',
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ((int) $this->input('delegate_user_id') === $this->delegatorId()) {
                $validator->errors()->add('delegate_user_id', 'Delegasi tidak boleh ke diri sendiri.');
            }
            if ($this->filled('delegator_user_id')
                && ! User::query()->whereKey($this->delegatorId())->where('is_active', true)->exists()) {
                $validator->errors()->add('delegator_user_id', 'Pemberi delegasi harus akun aktif.');
            }
        }];
    }

    public function delegatorId(): int
    {
        $user = $this->user();
        $given = $this->input('delegator_user_id');

        return is_numeric($given) ? (int) $given : (int) $user?->getAuthIdentifier();
    }

    /**
     * @return array{delegator_user_id: int, delegate_user_id: int, start_date: string, end_date: string, document_types: list<string>|null, reason: string}
     */
    public function delegationData(): array
    {
        $types = $this->input('document_types');

        return [
            'delegator_user_id' => $this->delegatorId(),
            'delegate_user_id' => (int) $this->input('delegate_user_id'),
            'start_date' => (string) $this->input('start_date'),
            'end_date' => (string) $this->input('end_date'),
            'document_types' => is_array($types) ? array_values(array_map('strval', $types)) : null,
            'reason' => (string) $this->input('reason'),
        ];
    }
}
