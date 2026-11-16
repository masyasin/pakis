<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAssignment;
use App\Domain\Customer\Support\AssignmentApplier;
use App\Domain\Numbering\NumberGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Tambah kios (FR-A13 data inti, BR-165 awal, BR-167, T-073):
 * - kode dari `NumberGenerator('customer')` bila `customer.code_mode = auto` (di dalam transaksi),
 *   selain itu kode ketikan (sudah divalidasi unik & format oleh request);
 * - status awal `prospect`, limit nonaktif (verifikasi & limit di T-074);
 * - penugasan pertama mulai hari ini (tanggal lokal) + cache area/sales di `customers`.
 */
final class CreateCustomer
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NumberGenerator $numbers,
    ) {}

    /** @param  array<string, mixed>  $data  sudah divalidasi CustomerRequest */
    public function handle(array $data, ?int $userId): Customer
    {
        return DB::transaction(function () use ($data, $userId) {
            $code = setting('customer.code_mode') === 'auto'
                ? $this->numbers->next('customer', isTaken: fn (string $n) => Customer::withTrashed()->where('code', $n)->exists())
                : (string) $data['code'];

            $areaId = (int) $data['sales_area_id'];
            $salesId = isset($data['assigned_sales_employee_id']) ? (int) $data['assigned_sales_employee_id'] : null;

            $customer = new Customer;
            $customer->forceFill([
                ...array_intersect_key($data, array_flip(Customer::PROFILE)),
                'code' => $code,
                'sales_area_id' => $areaId,
                'assigned_sales_employee_id' => $salesId,
                'status' => CustomerStatus::Prospect,
                'credit_limit' => '0',
                'credit_limit_active' => false,
                'created_by' => $userId,
                'updated_by' => $userId,
            ])->save();

            CustomerAssignment::query()->create([
                'customer_id' => $customer->id,
                'sales_area_id' => $areaId,
                'sales_employee_id' => $salesId,
                'start_date' => AssignmentApplier::today(),
                'reason' => 'Kios baru',
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->audit->record('customer.created', $customer, null, [
                ...$customer->audited(),
                'sales_area_id' => $areaId,
                'assigned_sales_employee_id' => $salesId,
                'status' => CustomerStatus::Prospect->value,
            ]);

            return $customer;
        });
    }
}
