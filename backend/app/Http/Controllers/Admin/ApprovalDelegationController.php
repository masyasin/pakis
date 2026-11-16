<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Approval\Actions\CreateDelegation;
use App\Domain\Approval\Actions\RevokeDelegation;
use App\Domain\Approval\ApproverResolver;
use App\Domain\Approval\Listings\ApprovalDelegationListing;
use App\Domain\Approval\Models\ApprovalDelegation;
use App\Domain\Approval\Support\ApprovableRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Approval\ApprovalDelegationRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Delegasi persetujuan (15 §5, BR-153(7)). Semua pengguna web: daftar = delegasi yang ia beri/terima;
 * pemegang `approvals.configure` melihat & mengelola semua. `{delegation}` di luar itu → 404.
 */
class ApprovalDelegationController extends Controller
{
    #[UsesListing(ApprovalDelegationListing::class)]
    public function index(Request $request): Response
    {
        $user = self::user($request);
        $configure = $user->can('approvals.configure');
        $query = ApprovalDelegation::query()->with(['delegator:id,name', 'delegate:id,name']);
        if (! $configure) {
            $query->where(fn (Builder $q) => $q->where('delegator_user_id', $user->id)->orWhere('delegate_user_id', $user->id));
        }
        $today = ApproverResolver::today();

        $result = ListingQuery::apply($query, new ApprovalDelegationListing, $request)
            ->through(fn (ApprovalDelegation $d) => [
                'id' => $d->id,
                'delegator' => $d->delegator === null ? null : ['id' => $d->delegator->id, 'name' => $d->delegator->name],
                'delegate' => $d->delegate === null ? null : ['id' => $d->delegate->id, 'name' => $d->delegate->name],
                'start_date' => $d->start_date->toDateString(),
                'end_date' => $d->end_date->toDateString(),
                'document_types' => $d->document_types === null ? null : array_map(
                    fn (string $t) => ['value' => $t, 'label' => ApprovableRegistry::label($t)], $d->document_types,
                ),
                'reason' => $d->reason,
                'state' => $d->stateOn($today),
                'can' => ['revoke' => Gate::forUser($user)->allows('revoke', $d)],
            ]);

        return Inertia::render('approvals/delegations', [
            ...$result->toInertia(),
            'documentTypes' => array_map(fn (array $o) => ['value' => $o['value'], 'label' => $o['label']], ApprovableRegistry::options()),
            'today' => $today,
            'can' => ['configure' => $configure],
        ]);
    }

    public function store(ApprovalDelegationRequest $request, CreateDelegation $action): RedirectResponse
    {
        $action->handle($request->delegationData(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Delegasi persetujuan dibuat.']);

        return to_route('admin.approval-delegations.index');
    }

    public function destroy(Request $request, ApprovalDelegation $delegation, RevokeDelegation $action): RedirectResponse
    {
        Gate::authorize('revoke', $delegation);
        $action->handle($delegation, self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Delegasi dicabut.']);

        return back(fallback: route('admin.approval-delegations.index'));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
