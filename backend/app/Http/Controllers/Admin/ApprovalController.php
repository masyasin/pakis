<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Approval\Actions\CancelApproval;
use App\Domain\Approval\Actions\DecideApprovalStep;
use App\Domain\Approval\Listings\ApprovalListing;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Support\ApprovableRegistry;
use App\Domain\Approval\Support\ApprovalPresenter;
use App\Domain\Approval\Support\ApprovalVisibility;
use App\Domain\Approval\Support\Rupiah;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\PasswordConfirmation;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kotak masuk & keputusan persetujuan (15 §5, BR-153, SEC-101, PA-27, PA-31). Route TIDAK memakai
 * `can:approvals.view`: calon penyetuju langkah aktif selalu boleh membuka tab "Menunggu saya",
 * detail, dan Setujui/Tolak. Tab "Saya ajukan"/"Semua" butuh `approvals.view` (403 bila diminta tanpa
 * izin). `{approval}` di luar ApprovalVisibility → 404. Semua aturan keputusan ada di ApprovalEngine;
 * galatnya (`ApiError`) dirender jalur Inertia PA-16.
 */
class ApprovalController extends Controller
{
    #[UsesListing(ApprovalListing::class)]
    public function index(Request $request, ApprovalVisibility $visibility, ApprovalPresenter $presenter): Response
    {
        $user = self::user($request);
        $listing = new ApprovalListing;
        $filter = $request->query('filter');
        $raw = is_array($filter) && array_key_exists('tab', $filter) ? $filter['tab'] : null;
        $parsed = $listing->spec()->filter('tab')?->parse($raw);
        $tab = $parsed !== null && $parsed->hasValue() && is_string($parsed->value) ? $parsed->value : ApprovalVisibility::TAB_MINE;
        abort_unless(ApprovalVisibility::tabAllowed($user, $tab), 403);

        $query = ApprovalRequest::query()->with(['steps', 'requester:id,name']);
        $visibility->constrainTab($query, $user, $tab);
        $recent = PasswordConfirmation::isRecent($request);

        $result = ListingQuery::apply($query, $listing, $request)
            ->through(fn (ApprovalRequest $r) => $presenter->row($r, $user, $recent));

        $tabs = [];
        foreach (ApprovalVisibility::tabLabels() as $value => $label) {
            if (ApprovalVisibility::tabAllowed($user, $value)) {
                $tabs[] = ['value' => $value, 'label' => $label, 'count' => $value === ApprovalVisibility::TAB_MINE ? $visibility->pendingCountFor($user) : null];
            }
        }

        return Inertia::render('approvals/index', [
            ...$result->toInertia(),
            'tab' => $tab,
            'tabs' => $tabs,
            'documentTypes' => array_map(fn (array $o) => ['value' => $o['value'], 'label' => $o['label']], ApprovableRegistry::options()),
            'reauthAmount' => Rupiah::format((string) setting('approval.reauth_amount')),
            'can' => [
                'viewAll' => $user->can('approvals.view'),
                'configure' => $user->can('approvals.configure'),
            ],
        ]);
    }

    public function show(Request $request, ApprovalRequest $approval, ApprovalPresenter $presenter): Response
    {
        $user = self::user($request);
        $approval->load(['steps.decider:id,name', 'steps.actingFor:id,name', 'requester:id,name']);

        return Inertia::render('approvals/show', [
            'approval' => $presenter->detail($approval, $user, PasswordConfirmation::isRecent($request)),
            'reauthAmount' => Rupiah::format((string) setting('approval.reauth_amount')),
        ]);
    }

    public function approve(Request $request, ApprovalRequest $approval, DecideApprovalStep $action): RedirectResponse
    {
        $data = $request->validate([
            'step' => ['required', 'integer', 'min:1', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            'current_password' => ['nullable', 'string'],
        ]);
        $confirmed = self::confirmPassword($request);
        $action->approve(self::user($request), $approval->id, (int) $data['step'], is_string($data['note'] ?? null) ? $data['note'] : null, $confirmed);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Persetujuan disimpan.']);

        return back(fallback: route('admin.approvals.index'));
    }

    public function reject(Request $request, ApprovalRequest $approval, DecideApprovalStep $action): RedirectResponse
    {
        $data = $request->validate([
            'step' => ['required', 'integer', 'min:1', 'max:255'],
            'note' => ['required', 'string', 'min:5', 'max:500'],
            'current_password' => ['nullable', 'string'],
        ], [
            'note.required' => 'Alasan penolakan wajib diisi.',
            'note.min' => 'Alasan penolakan minimal 5 karakter.',
        ]);
        $confirmed = self::confirmPassword($request);
        $action->reject(self::user($request), $approval->id, (int) $data['step'], trim((string) $data['note']), $confirmed);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Permintaan ditolak.']);

        return back(fallback: route('admin.approvals.index'));
    }

    public function cancel(Request $request, ApprovalRequest $approval, CancelApproval $action): RedirectResponse
    {
        // Alasan wajib bila dibatalkan pemegang approvals.configure (PA-38), diperiksa mesin.
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $action->handle(self::user($request), $approval->id, is_string($data['note'] ?? null) ? $data['note'] : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Permintaan persetujuan dibatalkan.']);

        return back(fallback: route('admin.approvals.index'));
    }

    /**
     * Konfirmasi password bersyarat nominal (SEC-101, pola SettingController): `current_password`
     * yang dikirim bersama keputusan diperiksa di sini lalu menandai sesi seperti `password.confirm`;
     * mesin yang memutuskan apakah nominal ini memerlukannya.
     */
    private static function confirmPassword(Request $request): bool
    {
        if ($request->filled('current_password')) {
            $request->validate(['current_password' => ['current_password:web']], [
                'current_password.current_password' => 'Password salah.',
            ]);
            $request->session()->passwordConfirmed();

            return true;
        }

        return PasswordConfirmation::isRecent($request);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
