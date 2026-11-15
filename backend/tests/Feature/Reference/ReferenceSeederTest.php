<?php

use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use Database\Seeders\ReferenceSeeder;

/*
 * 04 §4.1 / 08 §3: item referensi sistem tersedia di semua lingkungan, seeder idempoten.
 */

test('seeder mengisi 22 item sistem sesuai 08 §3', function () {
    $this->seed(ReferenceSeeder::class);

    $codes = fn (ReferenceGroup $g) => ReferenceItem::query()->where('group_code', $g->value)->orderBy('sort_order')->pluck('code')->all();

    expect(ReferenceItem::query()->count())->toBe(22)
        ->and(ReferenceItem::query()->where('is_system', false)->count())->toBe(0)
        ->and($codes(ReferenceGroup::LocationType))->toBe(['office', 'warehouse', 'store', 'other'])
        ->and($codes(ReferenceGroup::EmploymentStatus))->toBe(['permanent', 'contract', 'probation', 'daily'])
        ->and($codes(ReferenceGroup::CorrectionReason))->toBe(['forgot', 'gps_error', 'field_duty', 'system_error', 'phone_broken', 'phone_lost', 'phone_left', 'other'])
        ->and($codes(ReferenceGroup::OffboardingReason))->toBe(['resign', 'contract_end', 'terminated', 'retired', 'deceased', 'other']);
});

test('seeder idempoten dan tidak menimpa nama yang sudah diubah admin', function () {
    $this->seed(ReferenceSeeder::class);
    ReferenceItem::query()->where('group_code', 'location_type')->where('code', 'store')->update(['name' => 'Toko Pertanian']);

    $this->seed(ReferenceSeeder::class);

    expect(ReferenceItem::query()->count())->toBe(22)
        ->and(ReferenceItem::query()->where('code', 'store')->value('name'))->toBe('Toko Pertanian');
});

test('setiap grup enum punya item sistem dan pemakai sesuai 08 §3', function () {
    expect(collect(ReferenceGroup::cases())->map->value->all())
        ->toBe(['location_type', 'employment_status', 'correction_reason', 'offboarding_reason'])
        ->and(ReferenceGroup::LocationType->usages())->toBe([['locations', 'location_type_id']])
        ->and(ReferenceGroup::EmploymentStatus->usages())->toBe([['employees', 'employment_status_id']])
        ->and(ReferenceGroup::CorrectionReason->usages())->toBe([['attendance_corrections', 'reason_id']])
        ->and(ReferenceGroup::OffboardingReason->usages())->toBe([['employees', 'exit_reason_id']]);
});
