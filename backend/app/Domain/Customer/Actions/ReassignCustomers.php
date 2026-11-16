<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Events\CustomerReassigned;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAssignment;
use App\Domain\Customer\Support\AssignmentApplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Alihkan kios ke area/sales lain (15 §5 `admin.customers.reassign`, BR-167, T-073). Semua kios dalam
 * SATU transaksi: gagal satu = batal semua. Tanggal efektif H = hari ini atau nanti (tanggal lokal).
 *
 * Per kios (keputusan teknis PA-47):
 * 1. Penugasan terbuka yang masih TERTUNDA (mulai > hari ini, belum pernah berlaku) dihapus dan
 *    penugasan sebelumnya dibuka lagi — pengalihan kedua sebelum H mengganti yang tertunda.
 * 2. Sama dengan penugasan berlaku → tidak ada baris baru (hanya pembatalan tertunda, bila ada).
 * 3. Penugasan berlaku mulai H juga (dialihkan dua kali di hari yang sama) → diperbarui di tempat,
 *    tanpa baris berumur nol hari.
 * 4. Selain itu: tutup penugasan berlaku `end_date = H − 1`, buat baru `start_date = H`.
 * Cache `customers` disamakan bila penugasan terbuka sudah berlaku (H ≤ hari ini); yang bertanggal
 * nanti diterapkan job `customers:apply-assignments` pada hari H.
 */
final class ReassignCustomers
{
    /** Batas kios per aksi (BR-167). */
    public const MAX_CUSTOMERS = 500;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AssignmentApplier $applier,
    ) {}

    /**
     * @param  list<int>  $customerIds  sudah divalidasi: dalam scope pelaku & bukan merged/inactive
     * @return int jumlah kios yang penugasannya berubah
     */
    public function handle(array $customerIds, int $salesAreaId, ?int $salesEmployeeId, string $effectiveDate, ?string $reason, ?int $userId): int
    {
        if (count($customerIds) > self::MAX_CUSTOMERS) {
            throw new LogicException('Pengalihan melebihi '.self::MAX_CUSTOMERS.' kios.');
        }

        $today = AssignmentApplier::today();
        if ($effectiveDate < $today) {
            throw new LogicException('Tanggal efektif pengalihan tidak boleh lampau.');
        }

        $changed = DB::transaction(function () use ($customerIds, $salesAreaId, $salesEmployeeId, $effectiveDate, $reason, $userId, $today) {
            $changed = [];
            // Kunci urut id naik: dua pengalihan massal yang beririsan tidak saling deadlock.
            $customers = Customer::query()->whereKey($customerIds)->orderBy('id')->lockForUpdate()->get();

            foreach ($customers as $customer) {
                if ($this->reassignOne($customer, $salesAreaId, $salesEmployeeId, $effectiveDate, $reason, $userId, $today)) {
                    $changed[] = $customer->id;
                }
            }

            return $changed;
        });

        if ($changed !== []) {
            CustomerReassigned::dispatch($changed, $salesAreaId, $salesEmployeeId, $effectiveDate, $userId);
        }

        return count($changed);
    }

    private function reassignOne(Customer $customer, int $areaId, ?int $salesId, string $effective, ?string $reason, ?int $userId, string $today): bool
    {
        $open = CustomerAssignment::query()->where('customer_id', $customer->id)->whereNull('end_date')->lockForUpdate()->first();
        $before = [
            'sales_area_id' => $open?->sales_area_id,
            'sales_employee_id' => $open?->sales_employee_id,
            'start_date' => $open?->start_date->toDateString(),
        ];
        $cancelledPending = false;

        if ($open !== null && $open->start_date->toDateString() > $today) {
            $previous = CustomerAssignment::query()->where('customer_id', $customer->id)->whereKeyNot($open->id)
                ->orderByDesc('end_date')->orderByDesc('id')->lockForUpdate()->first();
            $open->delete();
            if ($previous !== null) {
                $previous->forceFill(['end_date' => null, 'updated_by' => $userId])->save();
            }
            $open = $previous;
            $cancelledPending = true;
        }

        $same = $open !== null && $open->sales_area_id === $areaId && $open->sales_employee_id === $salesId;

        if ($same) {
            // Tetap di penugasan berlaku; tidak ada baris baru.
        } elseif ($open !== null && $open->start_date->toDateString() >= $effective) {
            $open->forceFill(['sales_area_id' => $areaId, 'sales_employee_id' => $salesId, 'reason' => $reason, 'updated_by' => $userId])->save();
        } else {
            $open?->forceFill(['end_date' => CarbonImmutable::parse($effective)->subDay()->toDateString(), 'updated_by' => $userId])->save();
            $open = CustomerAssignment::query()->create([
                'customer_id' => $customer->id,
                'sales_area_id' => $areaId,
                'sales_employee_id' => $salesId,
                'start_date' => $effective,
                'reason' => $reason,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        }

        // Semua cabang di atas meninggalkan tepat satu penugasan terbuka.
        if ($open->start_date->toDateString() <= $today) {
            $this->applier->sync($customer, $open);
        }

        if ($same && ! $cancelledPending) {
            return false;
        }

        $this->audit->record('customer.reassigned', $customer, $before, [
            'sales_area_id' => $areaId,
            'sales_employee_id' => $salesId,
            'effective_date' => $same ? null : $effective,
            'reason' => $reason,
            'pending_cancelled' => $cancelledPending,
        ]);

        return true;
    }
}
