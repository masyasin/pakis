<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Customer\Actions\CreateCustomer;
use App\Domain\Customer\Actions\ReassignCustomers;
use App\Domain\Customer\Actions\UpdateCustomer;
use App\Domain\Customer\Listings\CustomerListing;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAssignment;
use App\Domain\Customer\Support\AssignmentApplier;
use App\Domain\Customer\Support\VerificationState;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Domain\Region\Models\Village;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CustomerRequest;
use App\Http\Requests\Customer\ReassignCustomersRequest;
use App\Models\User;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Phone\PhoneNormalizer;
use App\Support\Time\DisplayTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Kios / pelanggan (15 §5 `admin.customers.*`, FR-A13 data inti, FR-A17, BR-167, T-073). Scope
 * Pelanggan (15 §6.2): daftar lewat CustomerListing::exportQuery, route ber-ID lewat binding
 * ber-scope (404). HP pemilik penuh hanya untuk `customers.manage`, selain itu termasking (SEC-108).
 *
 * Detail bertab (`?tab=`, 15 §7.1): profil, riwayat area/sales. Tab kontak, verifikasi, limit, dan
 * riwayat perubahan ditambah T-074.
 */
class CustomerController extends Controller
{
    #[UsesListing(CustomerListing::class)]
    public function index(Request $request): Response
    {
        $user = self::user($request);
        $listing = new CustomerListing;
        $fullPhone = $user->can('viewFullPhone', Customer::class);

        $result = ListingQuery::apply($listing->exportQuery($user), $listing, $request)
            ->through(fn (Customer $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'store_name' => $c->store_name,
                'owner_name' => $c->owner_name,
                'owner_phone' => $fullPhone ? $c->owner_phone : PhoneNormalizer::mask($c->owner_phone),
                'customer_type' => $c->customerType?->name,
                'sales_area' => $c->salesArea !== null ? ['id' => $c->salesArea->id, 'code' => $c->salesArea->code, 'name' => $c->salesArea->name] : null,
                'assigned_sales' => $c->assignedSales !== null ? ['id' => $c->assignedSales->id, 'name' => $c->assignedSales->full_name] : null,
                'village' => $c->village?->name,
                'status' => $c->status->value,
                'status_label' => $c->status->label(),
                'is_verified' => $c->verified_at !== null,
                'created_at' => $c->created_at !== null ? DisplayTime::toLocal($c->created_at)->toIso8601String() : null,
            ]);

        return Inertia::render('customers/index', [
            ...$result->toInertia(),
            'phoneMasked' => ! $fullPhone,
            'can' => [
                'create' => $user->can('create', Customer::class),
                'reassign' => $user->can('reassign', Customer::class),
                'export' => $user->can('customers.export'),
            ],
            'maxReassign' => ReassignCustomers::MAX_CUSTOMERS,
            'today' => AssignmentApplier::today(),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Customer::class);

        return Inertia::render('customers/form', [
            ...self::formOptions(null),
            'customer' => null,
        ]);
    }

    public function store(CustomerRequest $request, CreateCustomer $action): RedirectResponse
    {
        Gate::authorize('create', Customer::class);
        $customer = $action->handle($request->customerData(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Kios \"{$customer->store_name}\" ditambahkan dengan kode {$customer->code} (status Prospek)."]);

        return to_route('admin.customers.show', $customer);
    }

    public function show(Request $request, Customer $customer, VerificationState $verification): Response
    {
        Gate::authorize('view', $customer);
        $user = self::user($request);
        $customer->load([
            'customerType:id,name', 'salesArea:id,code,name', 'assignedSales:id,full_name,employee_number',
            'homeStockLocation:id,code,name', 'village.district.regency.province',
        ]);
        $fullPhone = $user->can('viewFullPhone', Customer::class);

        $assignments = $customer->assignments()->with(['salesArea:id,code,name', 'salesEmployee:id,full_name'])
            ->orderByDesc('start_date')->orderByDesc('id')->get();
        $today = AssignmentApplier::today();

        return Inertia::render('customers/show', [
            'customer' => [
                ...self::detail($customer),
                'owner_phone' => $fullPhone ? $customer->owner_phone : PhoneNormalizer::mask($customer->owner_phone),
                'customer_type' => $customer->customerType?->name,
                'sales_area' => $customer->salesArea !== null ? ['id' => $customer->salesArea->id, 'code' => $customer->salesArea->code, 'name' => $customer->salesArea->name] : null,
                'assigned_sales' => $customer->assignedSales !== null ? ['id' => $customer->assignedSales->id, 'name' => $customer->assignedSales->full_name, 'employee_number' => $customer->assignedSales->employee_number] : null,
                'home_stock_location' => $customer->homeStockLocation !== null ? ['id' => $customer->homeStockLocation->id, 'code' => $customer->homeStockLocation->code, 'name' => $customer->homeStockLocation->name] : null,
                'region' => self::regionNames($customer->village),
                'status_label' => $customer->status->label(),
                'credit_limit' => $customer->credit_limit,
                'credit_limit_active' => $customer->credit_limit_active,
                'verified_at' => $customer->verified_at !== null ? DisplayTime::toLocal($customer->verified_at)->toIso8601String() : null,
                'created_at' => $customer->created_at !== null ? DisplayTime::toLocal($customer->created_at)->toIso8601String() : null,
            ],
            'assignments' => $assignments->map(fn (CustomerAssignment $a) => [
                'id' => $a->id,
                'sales_area' => $a->salesArea !== null ? ['id' => $a->salesArea->id, 'code' => $a->salesArea->code, 'name' => $a->salesArea->name] : null,
                'sales_employee' => $a->salesEmployee !== null ? ['id' => $a->salesEmployee->id, 'name' => $a->salesEmployee->full_name] : null,
                'start_date' => $a->start_date->toDateString(),
                'end_date' => $a->end_date?->toDateString(),
                'reason' => $a->reason,
                // `pending` = sudah dijadwalkan tapi belum berlaku; `current` = berlaku hari ini.
                'state' => $a->end_date === null
                    ? ($a->start_date->toDateString() > $today ? 'pending' : 'current')
                    : 'ended',
            ])->all(),
            'phoneMasked' => ! $fullPhone,
            // Tab verifikasi (T-074): keadaan OTP/telepon kantor/antrean kios ini.
            'verification' => $verification->for($customer, $user),
            'can' => [
                'update' => $user->can('update', $customer),
                'reassign' => $user->can('reassign', Customer::class),
            ],
            'today' => $today,
            'maxReassign' => ReassignCustomers::MAX_CUSTOMERS,
        ]);
    }

    public function edit(Customer $customer): Response
    {
        Gate::authorize('update', $customer);
        $customer->load(['village.district.regency.province', 'homeStockLocation:id,code,name']);

        return Inertia::render('customers/form', [
            ...self::formOptions($customer),
            'customer' => [
                ...self::detail($customer),
                'phone_editable' => UpdateCustomer::phoneEditable($customer),
            ],
        ]);
    }

    public function update(CustomerRequest $request, Customer $customer, UpdateCustomer $action): RedirectResponse
    {
        Gate::authorize('update', $customer);
        $saved = $action->handle($customer, $request->customerData(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Kios \"{$saved->store_name}\" disimpan."]);

        return to_route('admin.customers.show', $saved);
    }

    public function reassign(ReassignCustomersRequest $request, ReassignCustomers $action): RedirectResponse
    {
        $data = $request->reassignData();
        $changed = $action->handle($data['customer_ids'], $data['sales_area_id'], $data['sales_employee_id'], $data['effective_date'], $data['reason'], self::user($request)->id);

        $when = $data['effective_date'] === AssignmentApplier::today() ? 'mulai hari ini' : 'mulai '.$data['effective_date'];
        Inertia::flash('toast', ['type' => 'success', 'message' => $changed === 0
            ? 'Tidak ada perubahan: kios yang dipilih sudah di area dan sales itu.'
            : "{$changed} kios dialihkan {$when}."]);

        return back();
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'customers', self::user($request));
    }

    /** @return array<string, mixed> data isian form (HP lengkap: form hanya untuk `customers.manage`) */
    private static function detail(Customer $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'store_name' => $c->store_name,
            'owner_name' => $c->owner_name,
            'owner_phone' => $c->owner_phone,
            'customer_type_id' => $c->customer_type_id,
            'address' => $c->address,
            'village_code' => $c->village_code,
            'district_code' => $c->village?->district_code,
            'regency_code' => $c->village?->district?->regency_code,
            'province_code' => $c->village?->district?->regency?->province_code,
            'latitude' => $c->latitude,
            'longitude' => $c->longitude,
            'home_stock_location_id' => $c->home_stock_location_id,
            'sales_area_id' => $c->sales_area_id,
            'assigned_sales_employee_id' => $c->assigned_sales_employee_id,
            'term_days' => $c->term_days,
            'status' => $c->status->value,
            'is_verified' => $c->verified_at !== null,
        ];
    }

    /** @return array<string, mixed> */
    private static function formOptions(?Customer $customer): array
    {
        $types = ReferenceItem::query()->forGroup(ReferenceGroup::CustomerType)
            ->where(fn ($q) => $q->where('is_active', true)->when($customer !== null, fn ($o) => $o->orWhere('id', $customer?->customer_type_id)))
            ->orderBy('sort_order')->get(['id', 'name']);
        $village = $customer?->village;
        $home = $customer?->homeStockLocation;

        return [
            'customerTypes' => $types->map(fn (ReferenceItem $r) => ['value' => $r->id, 'label' => $r->name])->all(),
            'codeMode' => (string) setting('customer.code_mode'),
            'defaultTermDays' => (int) setting('customer.default_term_days'),
            // Label wilayah terpilih untuk select berjenjang (tanpa ini tampil sebagai kode).
            'regionLabels' => array_values(array_filter([
                $village?->district?->regency?->province ? ['value' => $village->district->regency->province->code, 'label' => $village->district->regency->province->name] : null,
                $village?->district?->regency ? ['value' => $village->district->regency->code, 'label' => $village->district->regency->name] : null,
                $village?->district ? ['value' => $village->district->code, 'label' => $village->district->name] : null,
                $village ? ['value' => $village->code, 'label' => $village->name] : null,
            ])),
            'homeStockLocationLabel' => $home !== null ? ['value' => $home->id, 'label' => $home->name, 'hint' => $home->code] : null,
        ];
    }

    /** @return array{province: string|null, regency: string|null, district: string|null, village: string|null} */
    private static function regionNames(?Village $village): array
    {
        return [
            'province' => $village?->district?->regency?->province?->name,
            'regency' => $village?->district?->regency?->name,
            'district' => $village?->district?->name,
            'village' => $village?->name,
        ];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
