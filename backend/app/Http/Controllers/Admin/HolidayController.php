<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Attendance\Actions\CreateHoliday;
use App\Domain\Attendance\Actions\DeleteHoliday;
use App\Domain\Attendance\Actions\UpdateHoliday;
use App\Domain\Attendance\Listings\HolidayListing;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Organization\Models\Location;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\HolidayRequest;
use App\Models\User;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Hari libur (05 §4.9, FR-033, BR-062). Izin dicek middleware route (`can:holidays.*`).
 * Tidak ber-scope data (06 §4: `holidays.view` ✅ untuk semua role).
 */
class HolidayController extends Controller
{
    #[UsesListing(HolidayListing::class)]
    public function index(Request $request): Response
    {
        $result = ListingQuery::apply(Holiday::query()->with('locations:id,name'), new HolidayListing, $request)
            ->through(fn (Holiday $h) => [
                'id' => $h->id,
                'start_date' => $h->start_date->toDateString(),
                'end_date' => $h->end_date->toDateString(),
                'days' => $h->dayCount(),
                'name' => $h->name,
                'description' => $h->description,
                'is_national' => $h->is_national,
                'applies_to_all' => $h->applies_to_all,
                'locations' => $h->locations->sortBy('name')->pluck('name')->values()->all(),
            ]);

        return Inertia::render('holidays/index', [
            ...$result->toInertia(),
            'can' => ['manage' => self::user($request)->can('holidays.manage')],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('holidays/form', ['holiday' => null, 'locationLabels' => []]);
    }

    public function store(HolidayRequest $request, CreateHoliday $action): RedirectResponse
    {
        $holiday = $action->handle($request->holidayData(), $request->locationIds(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Hari libur \"{$holiday->name}\" ditambahkan."]);

        return to_route('admin.holidays.index', ['filter' => ['year' => $holiday->start_date->format('Y')]]);
    }

    public function edit(Holiday $holiday): Response
    {
        $holiday->load('locations:id,name,code');

        return Inertia::render('holidays/form', [
            'holiday' => [
                'id' => $holiday->id,
                'start_date' => $holiday->start_date->toDateString(),
                'end_date' => $holiday->end_date->toDateString(),
                'name' => $holiday->name,
                'description' => $holiday->description,
                'is_national' => $holiday->is_national,
                'applies_to_all' => $holiday->applies_to_all,
                'location_ids' => $holiday->locations->pluck('id')->all(),
            ],
            // Label lokasi terpilih untuk select pencarian (tanpa ini tampil sebagai ID).
            'locationLabels' => $holiday->locations
                ->map(fn (Location $l) => ['value' => $l->id, 'label' => $l->name, 'hint' => $l->code])
                ->values()->all(),
        ]);
    }

    public function update(HolidayRequest $request, Holiday $holiday, UpdateHoliday $action): RedirectResponse
    {
        $holiday = $action->handle($holiday, $request->holidayData(), $request->locationIds(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Hari libur \"{$holiday->name}\" disimpan."]);

        return to_route('admin.holidays.index', ['filter' => ['year' => $holiday->start_date->format('Y')]]);
    }

    public function destroy(Holiday $holiday, DeleteHoliday $action): RedirectResponse
    {
        $action->handle($holiday);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Hari libur \"{$holiday->name}\" dihapus."]);

        return back(fallback: route('admin.holidays.index'));
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'holidays', self::user($request));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
