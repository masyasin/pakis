<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Actions\SetShiftPattern;
use App\Domain\Attendance\Listings\ScheduleCalendarListing;
use App\Domain\Attendance\Listings\ScheduleListing;
use App\Domain\Attendance\Models\ShiftPattern;
use App\Domain\Attendance\ScheduleResolver;
use App\Domain\Attendance\Support\ScheduleDates;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Models\Employee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ShiftPatternRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Jadwal: daftar pola & kalender (05 §4.9, FR-032, BR-061/BR-062). Izin dicek middleware route
 * (`can:schedules.*`); baris = pegawai AKTIF dalam scope data (06 §5).
 */
class ScheduleController extends Controller
{
    #[UsesListing(ScheduleListing::class)]
    public function index(Request $request, DataScope $scope): Response
    {
        $user = self::user($request);
        $query = self::employees($user, $scope)->with('currentShiftPattern');
        $result = ListingQuery::apply($query, new ScheduleListing, $request);

        /** @var list<Employee> $page */
        $page = $result->paginator()->items();
        $shifts = ShiftPattern::shiftsOf(array_filter(array_map(fn (Employee $e) => $e->currentShiftPattern, $page)));

        $rows = $result->through(fn (Employee $e) => [
            ...self::employeeCells($e),
            'effective_from' => $e->currentShiftPattern?->effective_from->toDateString(),
            'days' => $e->currentShiftPattern === null ? null : array_map(
                fn (?int $id) => $id === null ? null : $shifts->get($id)?->summary(),
                $e->currentShiftPattern->days(),
            ),
        ]);

        $canManage = $user->can('schedules.manage');

        return Inertia::render('schedules/index', [
            ...$rows->toInertia(),
            'can' => ['manage' => $canManage],
            'selectionLimit' => ShiftPatternRequest::MAX_EMPLOYEES,
            // "Semua hasil filter" di dialog Tetapkan pola: dihitung hanya saat diminta (partial
            // reload dengan URL filter saat itu), agar tidak basi setelah filter diganti. Lebih dari
            // batas → null: pengguna diminta mempersempit filter.
            'filteredIds' => Inertia::optional(function () use ($result, $canManage) {
                if (! $canManage) {
                    return null;
                }
                $ids = $result->query()->limit(ShiftPatternRequest::MAX_EMPLOYEES + 1)->pluck('employees.id')
                    ->map(fn ($id): int => (int) $id)->all();

                return count($ids) > ShiftPatternRequest::MAX_EMPLOYEES ? null : $ids;
            }),
        ]);
    }

    public function calendar(Request $request, DataScope $scope, ScheduleResolver $resolver): Response
    {
        $user = self::user($request);
        $result = ListingQuery::apply(self::employees($user, $scope), new ScheduleCalendarListing, $request);

        $month = $result->state()['filters']['month'] ?? ScheduleCalendarListing::currentMonth();
        $start = CarbonImmutable::createFromFormat('!Y-m', is_string($month) ? $month : ScheduleCalendarListing::currentMonth());
        $start = $start instanceof CarbonImmutable ? $start : CarbonImmutable::now()->startOfMonth();

        /** @var list<Employee> $page */
        $page = $result->paginator()->items();
        $schedules = $resolver->forRange(
            array_map(fn (Employee $e) => $e->id, $page),
            $start->toDateString(),
            $start->endOfMonth()->toDateString(),
        );

        $rows = $result->through(fn (Employee $e) => [
            ...self::employeeCells($e),
            // Urut tanggal; halaman menyusun kolom dari filter bulan.
            'cells' => array_map(fn ($s) => $s->toArray(), $schedules[$e->id] ?? []),
        ]);

        return Inertia::render('schedules/calendar', [
            ...$rows->toInertia(),
            'can' => ['manage' => $user->can('schedules.manage')],
        ]);
    }

    public function storePatterns(ShiftPatternRequest $request, SetShiftPattern $action): RedirectResponse
    {
        $ids = $request->employeeIds();
        $from = $request->effectiveFrom();
        $summary = $action->handle($ids, $from, $request->days(), self::user($request)->id);

        $date = ScheduleDates::long($from);
        $changed = $summary['created'] + $summary['updated'];
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $changed === 0
                ? 'Pola jadwal tidak berubah.'
                : "Pola jadwal ditetapkan untuk {$changed} pegawai, berlaku mulai {$date}.",
        ]);

        return back(fallback: route('admin.schedules.index'));
    }

    /** @return Builder<Employee> pegawai aktif dalam scope, dengan penugasan utama hari ini */
    private static function employees(User $user, DataScope $scope): Builder
    {
        $query = Employee::query()
            ->where('employees.status', EmployeeStatus::Active->value)
            ->with(['primaryAssignment.location:id,name', 'primaryAssignment.position:id,name']);

        return $scope->apply($query, $user, 'employees.id');
    }

    /** @return array{id: int, employee: array{id: int, name: string, number: string}, location: string|null, position: string|null} */
    private static function employeeCells(Employee $e): array
    {
        return [
            'id' => $e->id,
            'employee' => ['id' => $e->id, 'name' => $e->full_name, 'number' => $e->employee_number],
            'location' => $e->primaryAssignment?->location?->name,
            'position' => $e->primaryAssignment?->position?->name,
        ];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
