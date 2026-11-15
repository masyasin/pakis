<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Actions\CreateShift;
use App\Domain\Attendance\Actions\DeleteShift;
use App\Domain\Attendance\Actions\UpdateShift;
use App\Domain\Attendance\Listings\ShiftListing;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Support\ShiftScope;
use App\Domain\Attendance\Support\ShiftUsage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ShiftRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shift (05 §4.9, FR-031, BR-060). Izin dicek middleware route (`can:shifts.*`); shift di luar
 * scope pengguna tidak tampil di daftar dan 404 di route ber-ID (Shift::resolveRouteBinding).
 */
class ShiftController extends Controller
{
    #[UsesListing(ShiftListing::class)]
    public function index(Request $request, ShiftScope $scope, ShiftUsage $usage, DataScope $dataScope): Response
    {
        $user = self::user($request);
        $query = $scope->apply(Shift::query(), $user);

        $result = ListingQuery::apply($query, new ShiftListing, $request);
        // Jumlah pegawai dibatasi scope pelaku: Kepala Lokasi tidak melihat hitungan lokasi lain.
        $counts = $usage->employeeCounts(
            array_values(array_map(fn (Shift $s): int => $s->id, $result->paginator()->items())),
            $dataScope->visibleEmployeeIds($user),
        );

        $result = $result->through(fn (Shift $s) => [
            ...self::row($s),
            'employees_count' => $counts === null ? null : ($counts[$s->id] ?? 0),
        ]);

        return Inertia::render('shifts/index', [
            ...$result->toInertia(),
            'can' => ['manage' => $user->can('shifts.manage')],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('shifts/form', ['shift' => null]);
    }

    public function store(ShiftRequest $request, CreateShift $action): RedirectResponse
    {
        $shift = $action->handle($request->shiftData(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Shift \"{$shift->name}\" ditambahkan."]);

        return to_route('admin.shifts.index');
    }

    public function edit(Shift $shift): Response
    {
        return Inertia::render('shifts/form', ['shift' => self::row($shift)]);
    }

    public function update(ShiftRequest $request, Shift $shift, UpdateShift $action): RedirectResponse
    {
        $shift = $action->handle($shift, $request->shiftData(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Shift \"{$shift->name}\" disimpan."]);

        return to_route('admin.shifts.index');
    }

    public function destroy(Request $request, Shift $shift, DeleteShift $action): RedirectResponse
    {
        $action->handle($shift, self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Shift \"{$shift->name}\" dihapus."]);

        return to_route('admin.shifts.index');
    }

    private static function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** @return array<string, mixed> */
    private static function row(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'code' => $shift->code,
            'name' => $shift->name,
            'start_time' => Shift::hm($shift->start_time),
            'end_time' => Shift::hm($shift->end_time),
            'crosses_midnight' => $shift->crosses_midnight,
            'late_tolerance_min' => $shift->late_tolerance_min,
            'checkin_open_min' => $shift->checkin_open_min,
            'checkout_close_min' => $shift->checkout_close_min,
            'color' => $shift->color,
            'is_active' => $shift->is_active,
        ];
    }
}
