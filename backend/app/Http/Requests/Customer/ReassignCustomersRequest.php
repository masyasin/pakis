<?php

namespace App\Http\Requests\Customer;

use App\Domain\Access\DataScope;
use App\Domain\Customer\Actions\ReassignCustomers;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Support\AssignmentApplier;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Pengalihan massal kios (15 §5 `admin.customers.reassign`, BR-167). Izin `customers.manage` dicek
 * middleware route, Policy `reassign` (scope `self` belum boleh, PA-47) di authorize().
 *
 * Kios di luar scope pelaku → 404 (SEC-033, tidak membocorkan keberadaannya); kios dalam scope tapi
 * `inactive`/`merged` → galat isian. Area/sales tujuan dibatasi scope pelaku (supervisor).
 */
class ReassignCustomersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reassign', Customer::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $ids = $this->input('customer_ids');

        $this->merge([
            'customer_ids' => is_array($ids) ? array_values(array_unique(array_map(fn ($id) => is_numeric($id) ? (int) $id : $id, $ids), SORT_REGULAR)) : $ids,
            'sales_area_id' => is_numeric($this->input('sales_area_id')) ? (int) $this->input('sales_area_id') : $this->input('sales_area_id'),
            'sales_employee_id' => is_numeric($this->input('sales_employee_id')) ? (int) $this->input('sales_employee_id') : null,
            'reason' => is_string($this->input('reason')) && trim($this->input('reason')) !== '' ? trim($this->input('reason')) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_ids' => ['required', 'array', 'min:1', 'max:'.ReassignCustomers::MAX_CUSTOMERS],
            'customer_ids.*' => ['integer', 'min:1'],
            'sales_area_id' => ['required', 'integer', Rule::exists('sales_areas', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'sales_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('status', 'active')->whereNull('deleted_at')],
            // Tanggal lokal (`general.timezone`), bukan tanggal UTC server.
            'effective_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.AssignmentApplier::today()],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $actor = $this->user();
            if (! $actor instanceof User || $v->errors()->has('customer_ids') || $v->errors()->has('customer_ids.*')) {
                return;
            }
            $scope = app(DataScope::class);

            /** @var list<int> $ids */
            $ids = $this->input('customer_ids');
            $visible = $scope->forCustomer(Customer::query()->whereKey($ids), $actor, 'customers.id')->get(['id', 'status']);
            abort_if($visible->count() !== count($ids), 404);

            if ($visible->contains(fn (Customer $c) => ! $c->status->isSelectable())) {
                $v->errors()->add('customer_ids', 'Kios nonaktif atau sudah digabung tidak bisa dialihkan. Hapus dari pilihan.');
            }

            $area = $this->input('sales_area_id');
            $allowed = $scope->assignableSalesAreaIds($actor);
            if (is_int($area) && $allowed !== null && ! in_array($area, $allowed, true) && ! $v->errors()->has('sales_area_id')) {
                $v->errors()->add('sales_area_id', 'Area penjualan tidak ditemukan atau di luar wewenang Anda.');
            }

            $sales = $this->input('sales_employee_id');
            if (is_int($sales) && ! $scope->allows($actor, $sales)) {
                $v->errors()->add('sales_employee_id', 'Pegawai tidak ditemukan atau sudah nonaktif.');
            }
        });
    }

    /**
     * @return array{customer_ids: list<int>, sales_area_id: int, sales_employee_id: int|null, effective_date: string, reason: string|null}
     */
    public function reassignData(): array
    {
        /** @var list<int> $ids */
        $ids = $this->validated('customer_ids');

        return [
            'customer_ids' => $ids,
            'sales_area_id' => (int) $this->validated('sales_area_id'),
            'sales_employee_id' => $this->validated('sales_employee_id') !== null ? (int) $this->validated('sales_employee_id') : null,
            'effective_date' => (string) $this->validated('effective_date'),
            'reason' => $this->validated('reason'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_ids.required' => 'Pilih minimal satu kios.',
            'customer_ids.max' => 'Maksimal '.ReassignCustomers::MAX_CUSTOMERS.' kios sekali alihkan.',
            'sales_area_id.exists' => 'Area penjualan tidak ditemukan atau sudah nonaktif.',
            'sales_employee_id.exists' => 'Pegawai tidak ditemukan atau sudah nonaktif.',
            'effective_date.after_or_equal' => 'Tanggal berlaku paling cepat hari ini.',
            'effective_date.date_format' => 'Tanggal berlaku tidak sah.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'customer_ids' => 'kios',
            'sales_area_id' => 'area penjualan',
            'sales_employee_id' => 'sales',
            'effective_date' => 'tanggal berlaku',
            'reason' => 'alasan',
        ];
    }
}
