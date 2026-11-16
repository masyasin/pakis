<?php

use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use Database\Seeders\ReferenceSeeder;

/*
 * 04 §4.1 / 08 §3: item referensi sistem tersedia di semua lingkungan, seeder idempoten.
 */

test('seeder mengisi 43 item sistem sesuai 08 §3 + 15 §4.1 (unit & formulation, T-069)', function () {
    $this->seed(ReferenceSeeder::class);

    $codes = fn (ReferenceGroup $g) => ReferenceItem::query()->where('group_code', $g->value)->orderBy('sort_order')->pluck('code')->all();

    expect(ReferenceItem::query()->count())->toBe(43)
        ->and(ReferenceItem::query()->where('is_system', false)->count())->toBe(0)
        ->and($codes(ReferenceGroup::LocationType))->toBe(['office', 'warehouse', 'store', 'other'])
        ->and($codes(ReferenceGroup::EmploymentStatus))->toBe(['permanent', 'contract', 'probation', 'daily'])
        ->and($codes(ReferenceGroup::CorrectionReason))->toBe(['forgot', 'gps_error', 'field_duty', 'system_error', 'phone_broken', 'phone_lost', 'phone_left', 'other'])
        ->and($codes(ReferenceGroup::OffboardingReason))->toBe(['resign', 'contract_end', 'terminated', 'retired', 'deceased', 'other'])
        ->and($codes(ReferenceGroup::Unit))->toBe(['bottle', 'box', 'sachet', 'pcs', 'pack', 'kg', 'gram', 'liter', 'ml', 'sack', 'jerrycan', 'unit'])
        ->and($codes(ReferenceGroup::Formulation))->toBe(['ec', 'wp', 'sl', 'sc', 'wg', 'gr', 'od', 'cs', 'other']);

    // BR-150: satuan hitungan hanya bilangan bulat; satuan berat/volume boleh pecahan.
    $integerOnly = ReferenceItem::query()->where('group_code', 'unit')->get()
        ->filter(fn (ReferenceItem $i) => ($i->meta['integer_only'] ?? false) === true)->pluck('code')->sort()->values()->all();
    expect($integerOnly)->toBe(['bottle', 'box', 'jerrycan', 'pack', 'pcs', 'sachet', 'sack', 'unit']);
});

test('seeder idempoten dan tidak menimpa nama yang sudah diubah admin', function () {
    $this->seed(ReferenceSeeder::class);
    ReferenceItem::query()->where('group_code', 'location_type')->where('code', 'store')->update(['name' => 'Toko Pertanian']);

    $this->seed(ReferenceSeeder::class);

    expect(ReferenceItem::query()->count())->toBe(43)
        ->and(ReferenceItem::query()->where('code', 'store')->value('name'))->toBe('Toko Pertanian');
});

test('setiap grup enum punya item sistem dan pemakai sesuai 08 §3', function () {
    expect(collect(ReferenceGroup::cases())->map->value->all())
        ->toBe(['location_type', 'employment_status', 'correction_reason', 'offboarding_reason', 'unit', 'formulation'])
        ->and(ReferenceGroup::LocationType->usages())->toBe([['locations', 'location_type_id']])
        ->and(ReferenceGroup::EmploymentStatus->usages())->toBe([['employees', 'employment_status_id']])
        ->and(ReferenceGroup::CorrectionReason->usages())->toBe([['attendance_corrections', 'reason_id']])
        ->and(ReferenceGroup::OffboardingReason->usages())->toBe([['employees', 'exit_reason_id']])
        // Pemakai satuan & formulasi (product_units, products) ditambah T-070.
        ->and(ReferenceGroup::Unit->usages())->toBe([])
        ->and(ReferenceGroup::Formulation->usages())->toBe([]);
});
