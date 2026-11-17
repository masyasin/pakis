<?php

use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use Database\Seeders\ReferenceSeeder;

/*
 * 04 §4.1 / 08 §3: item referensi sistem tersedia di semua lingkungan, seeder idempoten.
 */

test('seeder mengisi 73 item sistem sesuai 08 §3 + 15 §4.1 (unit & formulation T-069, customer_type T-073, receipt_reject_reason T-080, purchase_return_reason T-081, opname_variance_reason T-083, quarantine_reason & disposal_reason T-084)', function () {
    $this->seed(ReferenceSeeder::class);

    $codes = fn (ReferenceGroup $g) => ReferenceItem::query()->where('group_code', $g->value)->orderBy('sort_order')->pluck('code')->all();

    expect(ReferenceItem::query()->count())->toBe(73)
        ->and(ReferenceItem::query()->where('is_system', false)->count())->toBe(0)
        ->and($codes(ReferenceGroup::LocationType))->toBe(['office', 'warehouse', 'store', 'other'])
        ->and($codes(ReferenceGroup::EmploymentStatus))->toBe(['permanent', 'contract', 'probation', 'daily'])
        ->and($codes(ReferenceGroup::CorrectionReason))->toBe(['forgot', 'gps_error', 'field_duty', 'system_error', 'phone_broken', 'phone_lost', 'phone_left', 'other'])
        ->and($codes(ReferenceGroup::OffboardingReason))->toBe(['resign', 'contract_end', 'terminated', 'retired', 'deceased', 'other'])
        ->and($codes(ReferenceGroup::Unit))->toBe(['bottle', 'box', 'sachet', 'pcs', 'pack', 'kg', 'gram', 'liter', 'ml', 'sack', 'jerrycan', 'unit'])
        ->and($codes(ReferenceGroup::Formulation))->toBe(['ec', 'wp', 'sl', 'sc', 'wg', 'gr', 'od', 'cs', 'other'])
        ->and($codes(ReferenceGroup::CustomerType))->toBe(['kiosk', 'retail', 'farmer_group', 'project', 'other'])
        ->and($codes(ReferenceGroup::ReceiptRejectReason))->toBe(['damaged', 'wrong_product', 'packaging', 'short_delivery', 'other'])
        ->and($codes(ReferenceGroup::PurchaseReturnReason))->toBe(['damaged', 'near_expiry', 'recall', 'wrong_item', 'other'])
        ->and($codes(ReferenceGroup::OpnameVarianceReason))->toBe(['miscount', 'damaged', 'lost', 'unrecorded_transaction', 'other'])
        ->and($codes(ReferenceGroup::QuarantineReason))->toBe(['damaged', 'leaking', 'suspect', 'expired', 'recall', 'other'])
        ->and($codes(ReferenceGroup::DisposalReason))->toBe(['expired', 'damaged', 'recall', 'other']);

    // BR-150: satuan hitungan hanya bilangan bulat; satuan berat/volume boleh pecahan.
    $integerOnly = ReferenceItem::query()->where('group_code', 'unit')->get()
        ->filter(fn (ReferenceItem $i) => ($i->meta['integer_only'] ?? false) === true)->pluck('code')->sort()->values()->all();
    expect($integerOnly)->toBe(['bottle', 'box', 'jerrycan', 'pack', 'pcs', 'sachet', 'sack', 'unit']);
});

test('seeder idempoten dan tidak menimpa nama yang sudah diubah admin', function () {
    $this->seed(ReferenceSeeder::class);
    ReferenceItem::query()->where('group_code', 'location_type')->where('code', 'store')->update(['name' => 'Toko Pertanian']);

    $this->seed(ReferenceSeeder::class);

    expect(ReferenceItem::query()->count())->toBe(73)
        ->and(ReferenceItem::query()->where('code', 'store')->value('name'))->toBe('Toko Pertanian');
});

test('setiap grup enum punya item sistem dan pemakai sesuai 08 §3', function () {
    expect(collect(ReferenceGroup::cases())->map->value->all())
        ->toBe(['location_type', 'employment_status', 'correction_reason', 'offboarding_reason', 'unit', 'formulation', 'customer_type', 'receipt_reject_reason', 'purchase_return_reason', 'opname_variance_reason', 'quarantine_reason', 'disposal_reason'])
        ->and(ReferenceGroup::LocationType->usages())->toBe([['locations', 'location_type_id']])
        ->and(ReferenceGroup::EmploymentStatus->usages())->toBe([['employees', 'employment_status_id']])
        ->and(ReferenceGroup::CorrectionReason->usages())->toBe([['attendance_corrections', 'reason_id']])
        ->and(ReferenceGroup::OffboardingReason->usages())->toBe([['employees', 'exit_reason_id']])
        // T-070: satuan dipakai satuan produk & satuan dasar; formulasi dipakai produk.
        ->and(ReferenceGroup::Unit->usages())->toBe([['product_units', 'unit_id'], ['products', 'base_unit_id']])
        ->and(ReferenceGroup::Formulation->usages())->toBe([['products', 'formulation_id']])
        ->and(ReferenceGroup::CustomerType->usages())->toBe([['customers', 'customer_type_id']])
        ->and(ReferenceGroup::ReceiptRejectReason->usages())->toBe([['goods_receipt_lines', 'reject_reason_id']])
        ->and(ReferenceGroup::PurchaseReturnReason->usages())->toBe([['purchase_returns', 'reason_id']])
        ->and(ReferenceGroup::OpnameVarianceReason->usages())->toBe([['stock_opname_lines', 'variance_reason_id']])
        ->and(ReferenceGroup::QuarantineReason->usages())->toBe([['stock_batches', 'quarantine_reason_id']])
        ->and(ReferenceGroup::DisposalReason->usages())->toBe([['disposal_reports', 'reason_id']]);
});
