<?php

namespace App\Domain\Customer\Support;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAssignment;
use App\Support\Time\DisplayTime;
use Illuminate\Support\Facades\DB;

/**
 * Salin penugasan terbuka yang sudah berlaku ke cache `customers.sales_area_id` /
 * `assigned_sales_employee_id` (BR-167). Dipakai ReassignCustomers (pengalihan hari ini) dan job
 * harian `customers:apply-assignments` (pengalihan bertanggal yang jatuh tempo).
 *
 * Idempoten: hanya kios yang cachenya berbeda dari penugasan terbukanya yang disentuh, jadi job
 * yang terlewat sehari atau dijalankan dua kali tetap menghasilkan keadaan yang sama.
 */
final class AssignmentApplier
{
    public static function today(): string
    {
        return DisplayTime::toLocal(now())->toDateString();
    }

    /** Terapkan semua penugasan yang mulai ≤ `$today` (tanggal lokal). Mengembalikan jumlah kios. */
    public function applyDue(?string $today = null): int
    {
        $today ??= self::today();
        $count = 0;

        CustomerAssignment::query()
            ->join('customers', 'customers.id', '=', 'customer_assignments.customer_id')
            ->whereNull('customer_assignments.end_date')
            ->where('customer_assignments.start_date', '<=', $today)
            ->where(fn ($q) => $q->whereColumn('customers.sales_area_id', '<>', 'customer_assignments.sales_area_id')
                ->orWhereRaw('NOT (customers.assigned_sales_employee_id <=> customer_assignments.sales_employee_id)'))
            ->select('customer_assignments.*')
            ->orderBy('customer_assignments.id')
            ->chunkById(200, function ($rows) use (&$count, $today) {
                foreach ($rows as $assignment) {
                    /** @var CustomerAssignment $assignment */
                    DB::transaction(function () use ($assignment, $today, &$count) {
                        $customer = Customer::withTrashed()->lockForUpdate()->find($assignment->customer_id);
                        $open = CustomerAssignment::query()->whereKey($assignment->id)->whereNull('end_date')->first();
                        // Diperiksa ulang di dalam kunci: pengalihan bersamaan bisa sudah mengganti barisnya.
                        if ($customer !== null && $open !== null && $open->start_date->toDateString() <= $today && $this->sync($customer, $open)) {
                            $count++;
                        }
                    });
                }
            }, 'customer_assignments.id', 'id');

        return $count;
    }

    /** Samakan cache kios dengan penugasan ini. True bila ada yang berubah. */
    public function sync(Customer $customer, CustomerAssignment $assignment): bool
    {
        if ($customer->sales_area_id === $assignment->sales_area_id && $customer->assigned_sales_employee_id === $assignment->sales_employee_id) {
            return false;
        }

        // Lewat query builder: cache bukan perubahan profil, sudah diaudit sebagai `customer.reassigned`.
        DB::table('customers')->where('id', $customer->id)->update([
            'sales_area_id' => $assignment->sales_area_id,
            'assigned_sales_employee_id' => $assignment->sales_employee_id,
            'updated_at' => now(),
        ]);
        $customer->sales_area_id = $assignment->sales_area_id;
        $customer->assigned_sales_employee_id = $assignment->sales_employee_id;
        $customer->syncOriginalAttributes(['sales_area_id', 'assigned_sales_employee_id']);

        return true;
    }
}
