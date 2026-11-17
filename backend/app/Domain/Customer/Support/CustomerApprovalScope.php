<?php

namespace App\Domain\Customer\Support;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Models\ApprovalStep;
use App\Domain\Customer\Models\Customer;
use App\Domain\Inventory\Models\StockLocation;

/**
 * Scope penyetuju dokumen approval kios (PA-53), satu tempat untuk aktivasi, ganti HP, limit, dan
 * gabung: pegawai = sales kios (`direct_supervisor` = atasan sales), lokasi = lokasi Fase 0 induk
 * gudang asal kios (gudang kios, atau gudang area).
 */
final class CustomerApprovalScope
{
    public static function locationId(Customer $customer): ?int
    {
        $stockLocationId = $customer->home_stock_location_id ?? $customer->salesArea?->home_stock_location_id;

        return $stockLocationId === null ? null : StockLocation::query()->withTrashed()->whereKey($stockLocationId)->value('location_id');
    }

    public static function employeeId(Customer $customer): ?int
    {
        return $customer->assigned_sales_employee_id;
    }

    /** Pemutus langkah terakhir (penyetuju akhir); null bila disetujui otomatis tanpa aturan. */
    public static function finalDecider(?ApprovalRequest $request): ?int
    {
        if ($request === null) {
            return null;
        }
        $last = $request->steps->filter(fn (ApprovalStep $s) => $s->decided_by !== null)->sortByDesc('step_order')->first();

        return $last?->decided_by;
    }
}
