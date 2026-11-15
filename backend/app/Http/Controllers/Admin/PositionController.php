<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Organization\Actions\CreatePosition;
use App\Domain\Organization\Actions\DeletePosition;
use App\Domain\Organization\Actions\UpdatePosition;
use App\Domain\Organization\Enums\AttendanceMode;
use App\Domain\Organization\Listings\PositionListing;
use App\Domain\Organization\Models\Position;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\PositionRequest;
use App\Models\User;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Jabatan (05 §4.5, FR-024, BR-042). Tidak ber-scope data (06 §5). */
class PositionController extends Controller
{
    #[UsesListing(PositionListing::class)]
    public function index(Request $request): Response
    {
        $result = ListingQuery::apply(Position::query()->with(['department:id,name', 'parent:id,name']), new PositionListing, $request)
            ->through(fn (Position $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'department' => $p->department?->name,
                'parent' => $p->parent?->name,
                'level' => $p->level,
                'attendance_mode' => $p->attendance_mode->value,
                'attendance_mode_label' => $p->attendance_mode->label(),
                'is_active' => $p->is_active,
            ]);

        return Inertia::render('positions/index', [
            ...$result->toInertia(),
            'can' => ['manage' => self::user($request)->can('positions.manage')],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('positions/form', [...self::formOptions(), 'position' => null, 'labels' => []]);
    }

    public function store(PositionRequest $request, CreatePosition $action): RedirectResponse
    {
        $position = $action->handle($request->positionData(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Jabatan \"{$position->name}\" ditambahkan."]);

        return to_route('admin.positions.index');
    }

    public function edit(Position $position): Response
    {
        Gate::authorize('update', $position);
        $position->load(['department:id,name', 'parent:id,name']);

        return Inertia::render('positions/form', [
            ...self::formOptions(),
            'position' => [
                'id' => $position->id,
                'code' => $position->code,
                'name' => $position->name,
                'department_id' => $position->department_id,
                'parent_position_id' => $position->parent_position_id,
                'level' => $position->level,
                'attendance_mode' => $position->attendance_mode->value,
                'is_active' => $position->is_active,
            ],
            // Label terpilih untuk select pencarian (tanpa ini tampil sebagai ID).
            'labels' => array_filter([
                'departments' => $position->department ? [['value' => $position->department->id, 'label' => $position->department->name]] : null,
                'positions' => $position->parent ? [['value' => $position->parent->id, 'label' => $position->parent->name]] : null,
            ]),
        ]);
    }

    public function update(PositionRequest $request, Position $position, UpdatePosition $action): RedirectResponse
    {
        Gate::authorize('update', $position);
        $position = $action->handle($position, $request->positionData(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Jabatan \"{$position->name}\" disimpan."]);

        return to_route('admin.positions.index');
    }

    public function destroy(Request $request, Position $position, DeletePosition $action): RedirectResponse
    {
        Gate::authorize('delete', $position);
        $action->handle($position, self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Jabatan \"{$position->name}\" dihapus."]);

        return to_route('admin.positions.index');
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'positions', self::user($request));
    }

    /** @return array<string, mixed> */
    private static function formOptions(): array
    {
        return [
            'modes' => AttendanceMode::options(),
            'levelRange' => [PositionRequest::LEVEL_MIN, PositionRequest::LEVEL_MAX],
        ];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
