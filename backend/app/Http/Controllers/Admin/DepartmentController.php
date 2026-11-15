<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Organization\Actions\CreateDepartment;
use App\Domain\Organization\Actions\DeleteDepartment;
use App\Domain\Organization\Actions\UpdateDepartment;
use App\Domain\Organization\Listings\DepartmentListing;
use App\Domain\Organization\Models\Department;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\DepartmentRequest;
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

/** Departemen (05 §4.5, FR-023). Tidak ber-scope data (06 §5). */
class DepartmentController extends Controller
{
    #[UsesListing(DepartmentListing::class)]
    public function index(Request $request): Response
    {
        $result = ListingQuery::apply(Department::query()->with('parent:id,name'), new DepartmentListing, $request)
            ->through(fn (Department $d) => [
                'id' => $d->id,
                'code' => $d->code,
                'name' => $d->name,
                'parent' => $d->parent?->name,
                'is_active' => $d->is_active,
            ]);

        return Inertia::render('departments/index', [
            ...$result->toInertia(),
            'can' => ['manage' => self::user($request)->can('departments.manage')],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('departments/form', ['department' => null, 'parentLabel' => null]);
    }

    public function store(DepartmentRequest $request, CreateDepartment $action): RedirectResponse
    {
        $department = $action->handle($request->departmentData(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Departemen \"{$department->name}\" ditambahkan."]);

        return to_route('admin.departments.index');
    }

    public function edit(Department $department): Response
    {
        Gate::authorize('update', $department);
        $department->load('parent:id,name');

        return Inertia::render('departments/form', [
            'department' => [
                'id' => $department->id,
                'code' => $department->code,
                'name' => $department->name,
                'parent_id' => $department->parent_id,
                'is_active' => $department->is_active,
            ],
            // Label induk terpilih untuk select pencarian (tanpa ini tampil sebagai ID).
            'parentLabel' => $department->parent ? ['value' => $department->parent->id, 'label' => $department->parent->name] : null,
        ]);
    }

    public function update(DepartmentRequest $request, Department $department, UpdateDepartment $action): RedirectResponse
    {
        Gate::authorize('update', $department);
        $department = $action->handle($department, $request->departmentData(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Departemen \"{$department->name}\" disimpan."]);

        return to_route('admin.departments.index');
    }

    public function destroy(Request $request, Department $department, DeleteDepartment $action): RedirectResponse
    {
        Gate::authorize('delete', $department);
        $action->handle($department, self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Departemen \"{$department->name}\" dihapus."]);

        return to_route('admin.departments.index');
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'departments', self::user($request));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
