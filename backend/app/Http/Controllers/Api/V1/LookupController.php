<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Http\Controllers\Controller;
use App\Support\Api\RequestId;
use Illuminate\Http\JsonResponse;

/**
 * Pilihan untuk form Pakis Pro (05 §5.1, keputusan Mas 16 Nov 2026): alasan koreksi dibaca dari
 * `reference_items` agar alasan yang ditambah/dinonaktifkan admin langsung berlaku di HP. Daftar
 * pendek (grup referensi), jadi tanpa paging; hanya item aktif, urut `sort_order`.
 */
class LookupController extends Controller
{
    public function correctionReasons(): JsonResponse
    {
        $items = ReferenceItem::query()->forGroup(ReferenceGroup::CorrectionReason)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->get(['code', 'name'])
            ->map(fn (ReferenceItem $r) => ['code' => $r->code, 'name' => $r->name])->values()->all();

        return response()->json(['data' => $items, 'meta' => ['request_id' => RequestId::current()]]);
    }
}
