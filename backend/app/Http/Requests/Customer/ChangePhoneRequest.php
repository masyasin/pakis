<?php

namespace App\Http\Requests\Customer;

use App\Domain\Customer\Enums\VerificationMethod;
use App\Domain\Customer\Models\Customer;
use App\Support\Phone\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajukan ganti HP kios (15 §5 `admin.customers.change-phone`, BR-166(4)). Izin `customers.change_phone`
 * dicek middleware + Policy; kios di luar scope 404 lewat binding. HP baru dinormalisasi `62…`.
 */
class ChangePhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer && ($this->user()?->can('changePhone', $customer) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $trim = fn (string $key) => is_string($this->input($key)) ? trim($this->input($key)) : $this->input($key);
        $this->merge([
            'new_phone' => PhoneNormalizer::forInput($this->input('new_phone')),
            'otp_code' => $trim('otp_code'),
            'office_call_note' => $trim('office_call_note'),
            'reason' => $trim('reason'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $length = (int) setting('auth.otp_length');
        $method = $this->input('method');

        return [
            'new_phone' => ['required', 'string', 'regex:'.PhoneNormalizer::STORED_PATTERN],
            'method' => ['required', 'string', Rule::enum(VerificationMethod::class)],
            'otp_code' => ['nullable', Rule::requiredIf($method === VerificationMethod::Otp->value), 'string', 'regex:/^\d{'.$length.'}$/'],
            'office_call_note' => ['nullable', Rule::requiredIf($method === VerificationMethod::OfficeCall->value), 'string', 'min:10', 'max:500'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    /** @return array{new_phone: string, method: string, otp_code: string|null, office_call_note: string|null, reason: string} */
    public function changeData(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return [
            'new_phone' => (string) $data['new_phone'],
            'method' => (string) $data['method'],
            'otp_code' => isset($data['otp_code']) ? (string) $data['otp_code'] : null,
            'office_call_note' => isset($data['office_call_note']) ? (string) $data['office_call_note'] : null,
            'reason' => (string) $data['reason'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'new_phone.regex' => PhoneNormalizer::INVALID_MESSAGE,
            'otp_code.required' => 'Masukkan kode OTP yang dikirim ke HP lama pemilik.',
            'otp_code.regex' => 'Kode OTP tidak sesuai format.',
            'office_call_note.required' => 'Tulis ringkasan telepon ke HP lama pemilik kios.',
            'office_call_note.min' => 'Ringkasan telepon minimal 10 karakter.',
            'reason.min' => 'Alasan ganti HP minimal 5 karakter.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'new_phone' => 'HP baru',
            'method' => 'bukti nomor lama',
            'otp_code' => 'kode OTP',
            'office_call_note' => 'catatan telepon kantor',
            'reason' => 'alasan',
        ];
    }
}
