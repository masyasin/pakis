<?php

namespace App\Http\Requests\Customer;

use App\Domain\Customer\Enums\StatusAction;
use App\Domain\Customer\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Tahan/lepas/nonaktif/aktifkan kembali kios (15 §5 `admin.customers.status`, §6.6): alasan wajib. */
class CustomerStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer && ($this->user()?->can('changeStatus', $customer) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => is_string($this->input('reason')) ? trim($this->input('reason')) : $this->input('reason')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::enum(StatusAction::class)],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    public function action(): StatusAction
    {
        return StatusAction::from((string) $this->validated()['action']);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Tulis alasan perubahan status.', 'reason.min' => 'Alasan minimal 5 karakter.'];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['action' => 'aksi', 'reason' => 'alasan'];
    }
}
