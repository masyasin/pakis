<?php

namespace App\Http\Requests\Approval;

use App\Domain\Access\Support\PermissionCatalog;
use App\Domain\Approval\Enums\ApproverType;
use App\Domain\Approval\Support\ApprovableRegistry;
use App\Domain\Settings\DecimalString;
use App\Domain\Settings\SettingRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Isian aturan approval (15 §4.2, FR-A02). Izin `approvals.configure` + konfirmasi password dicek
 * middleware route. Nominal = string desimal 2 digit (tanpa float); batas atas > batas bawah (juga
 * CHECK `approval_rules_amount_chk`); kunci kondisi hanya yang diizinkan untuk jenis dokumennya.
 */
class ApprovalRuleRequest extends FormRequest
{
    public const MAX_STEPS = 5;

    public const MONEY = '/^\d{1,16}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $money = function (string $key): ?string {
            $value = $this->input($key);
            if ($value === null || (is_string($value) && trim($value) === '')) {
                return null;
            }

            return is_scalar($value) ? trim((string) $value) : null;
        };
        $steps = is_array($this->input('steps')) ? array_values($this->input('steps')) : $this->input('steps');
        if (is_array($steps)) {
            $steps = array_map(fn ($s) => is_array($s) ? [
                ...$s,
                'permission_name' => ($s['permission_name'] ?? null) === '' ? null : ($s['permission_name'] ?? null),
                'escalate_after_hours' => ($s['escalate_after_hours'] ?? null) === '' ? null : ($s['escalate_after_hours'] ?? null),
                'escalation_permission' => ($s['escalation_permission'] ?? null) === '' ? null : ($s['escalation_permission'] ?? null),
            ] : $s, $steps);
        }

        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'min_amount' => $money('min_amount'),
            'max_amount' => $money('max_amount'),
            'conditions' => $this->input('conditions') === [] ? null : $this->input('conditions'),
            'priority' => $this->input('priority') ?? 100,
            'is_active' => $this->boolean('is_active', true),
            'steps' => $steps,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $hours = SettingRegistry::get('approval.default_escalation_hours');
        $permissions = PermissionCatalog::names();

        return [
            'document_type' => ['required', 'string', Rule::in(ApprovableRegistry::types())],
            'name' => ['required', 'string', 'max:120'],
            'min_amount' => ['nullable', 'string', 'regex:'.self::MONEY],
            'max_amount' => ['nullable', 'string', 'regex:'.self::MONEY],
            'conditions' => ['nullable', 'array'],
            'conditions.*' => ['array', 'min:1', 'max:50'],
            'conditions.*.*' => ['required'],
            'priority' => ['required', 'integer', 'min:1', 'max:65535'],
            'is_active' => ['boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:'.self::MAX_STEPS],
            'steps.*.approver_type' => ['required', Rule::enum(ApproverType::class)],
            'steps.*.permission_name' => ['nullable', 'required_if:steps.*.approver_type,permission', Rule::in($permissions)],
            'steps.*.escalate_after_hours' => ['nullable', 'integer', 'min:'.($hours->min ?? 1), 'max:'.($hours->max ?? 168)],
            'steps.*.escalation_permission' => ['nullable', Rule::in($permissions)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'min_amount.regex' => 'Nominal harus angka rupiah, maksimal 2 desimal.',
            'max_amount.regex' => 'Nominal harus angka rupiah, maksimal 2 desimal.',
            'steps.required' => 'Tambahkan minimal satu langkah penyetuju.',
            'steps.max' => 'Maksimal '.self::MAX_STEPS.' langkah.',
            'steps.*.permission_name.required_if' => 'Pilih izin penyetuju untuk langkah ini.',
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $min = $this->input('min_amount');
            $max = $this->input('max_amount');
            if (is_string($min) && is_string($max) && DecimalString::compare($max, $min) <= 0) {
                $validator->errors()->add('max_amount', 'Batas atas harus lebih besar dari batas bawah.');
            }

            $allowed = ApprovableRegistry::conditions((string) $this->input('document_type'));
            foreach (array_keys((array) ($this->input('conditions') ?? [])) as $key) {
                if (! in_array((string) $key, $allowed, true)) {
                    $validator->errors()->add('conditions', "Kondisi [{$key}] tidak berlaku untuk jenis dokumen ini.");
                }
            }
        }];
    }

    /**
     * @return array{document_type: string, name: string, min_amount: string|null, max_amount: string|null, conditions: array<string, list<int|string>>|null, priority: int, is_active: bool}
     */
    public function ruleData(): array
    {
        $min = $this->input('min_amount');
        $max = $this->input('max_amount');
        /** @var array<string, array<int|string, int|string>>|null $conditions */
        $conditions = $this->input('conditions');

        return [
            'document_type' => (string) $this->input('document_type'),
            'name' => (string) $this->input('name'),
            'min_amount' => is_string($min) ? DecimalString::normalize($min, 2) : null,
            'max_amount' => is_string($max) ? DecimalString::normalize($max, 2) : null,
            'conditions' => $conditions === null ? null : array_map(fn ($v) => array_values((array) $v), $conditions),
            'priority' => (int) $this->input('priority'),
            'is_active' => (bool) $this->input('is_active'),
        ];
    }

    /**
     * @return list<array{approver_type: string, permission_name: string|null, escalate_after_hours: int|null, escalation_permission: string|null}>
     */
    public function steps(): array
    {
        return array_map(fn (array $s) => [
            'approver_type' => (string) $s['approver_type'],
            'permission_name' => isset($s['permission_name']) ? (string) $s['permission_name'] : null,
            'escalate_after_hours' => isset($s['escalate_after_hours']) ? (int) $s['escalate_after_hours'] : null,
            'escalation_permission' => isset($s['escalation_permission']) ? (string) $s['escalation_permission'] : null,
        ], array_values((array) $this->input('steps')));
    }
}
