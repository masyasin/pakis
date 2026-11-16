<?php

namespace App\Domain\Approval\Support;

use App\Domain\Access\DataScope;
use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Access\Support\ScopeResolver;
use App\Domain\Approval\ApproverResolver;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Models\ApprovalStep;
use App\Domain\Organization\Support\LocationScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Siapa melihat permintaan apa (15 §5, PA-27). Tiga tab kotak masuk:
 *   - `mine` (Menunggu saya): permintaan `pending` yang langkah aktifnya bisa diputuskan pengguna
 *     (langsung atau delegasi). Selalu terbuka, tanpa `approvals.view` (PA-27).
 *   - `submitted` (Saya ajukan) & `all` (Semua, sesuai scope): butuh `approvals.view`.
 * Detail/aksi `{approval}` terlihat oleh calon penyetuju langkah aktif, pengaju, pemutus langkah
 * sebelumnya, atau pemegang `approvals.view` dalam scope. Selain itu 404 (SEC-033).
 *
 * "Menunggu saya" dihitung di PHP lewat ApproverResolver (bukan SQL tersendiri) supaya isi kotak
 * masuk selalu sama dengan siapa yang benar-benar lolos pemeriksaan keputusan. Jumlah permintaan
 * `pending` satu perusahaan kecil, jadi biayanya wajar (dicatat di laporan T-067).
 */
final class ApprovalVisibility
{
    public const TAB_MINE = 'mine';

    public const TAB_SUBMITTED = 'submitted';

    public const TAB_ALL = 'all';

    /** Pembatalan oleh pengaju sebelum ada keputusan (BR-153(9)). */
    public const CANCEL_REQUESTER = 'requester';

    /** Pembatalan oleh pemegang `approvals.configure`, alasan wajib (PA-38). */
    public const CANCEL_ADMIN = 'admin';

    public function __construct(
        private readonly ApproverResolver $resolver,
        private readonly DataScope $scope,
        private readonly LocationScope $locations,
    ) {}

    /** @return array<string, string> tab => label */
    public static function tabLabels(): array
    {
        return [self::TAB_MINE => 'Menunggu saya', self::TAB_SUBMITTED => 'Saya ajukan', self::TAB_ALL => 'Semua'];
    }

    public static function tabAllowed(User $user, string $tab): bool
    {
        return $tab === self::TAB_MINE || $user->can('approvals.view');
    }

    public function canView(User $user, ApprovalRequest $request): bool
    {
        if ($request->requester_user_id === $user->id) {
            return true;
        }
        foreach ($request->steps as $step) {
            if ($step->decided_by === $user->id || $step->acting_for_user_id === $user->id) {
                return true;
            }
        }
        if ($this->canDecideNow($user, $request)) {
            return true;
        }
        if ($this->canCancelAsAdmin($user, $request)) {
            // Pemegang approvals.configure perlu melihat permintaan yang bisa ia batalkan (PA-38),
            // tetap dibatasi scope-nya: role kustom ber-scope sempit tidak melihat lokasi lain.
            return true;
        }

        return $user->can('approvals.view') && $this->resolver->inScope($user, $request);
    }

    /**
     * Jalur pembatalan yang berlaku bagi pengguna saat ini, atau null bila tidak boleh. Pengaju
     * sebelum ada keputusan memakai jalur pengaju walau ia juga pemegang configure (alasan opsional).
     */
    public function cancelMode(User $user, ApprovalRequest $request): ?string
    {
        if ($request->status !== ApprovalStatus::Pending) {
            return null;
        }
        $decided = $request->steps->contains(fn (ApprovalStep $s) => $s->status->isDecided());
        if ($request->requester_user_id === $user->id && ! $decided) {
            return self::CANCEL_REQUESTER;
        }

        return $this->canCancelAsAdmin($user, $request) ? self::CANCEL_ADMIN : null;
    }

    /** Pembatalan admin (PA-38): pending, pemegang configure, dan dokumen dalam scope (seperti penyetuju eskalasi). */
    private function canCancelAsAdmin(User $user, ApprovalRequest $request): bool
    {
        return $request->status === ApprovalStatus::Pending
            && self::isCancelAdmin($user)
            && $this->resolver->inScope($user, $request);
    }

    /** Pemegang `approvals.configure` dari role (bukan Gate), aktif, bukan Super Admin (PA-37, PA-38). */
    public static function isCancelAdmin(User $user): bool
    {
        return $user->is_active
            && ! $user->hasRole(RoleCatalog::SUPER_ADMIN)
            && ApproverResolver::holds($user, 'approvals.configure');
    }

    public function canDecideNow(User $user, ApprovalRequest $request): bool
    {
        if ($request->status !== ApprovalStatus::Pending) {
            return false;
        }
        $step = $request->currentStep();

        return $step !== null && $this->resolver->rightOf($user, $request, $step)->allowed;
    }

    /**
     * ID permintaan yang menunggu keputusan pengguna (tab `mine`, angka menu, widget dasbor).
     *
     * @return list<int>
     */
    public function pendingIdsFor(User $user): array
    {
        return array_values(ApprovalRequest::query()
            ->with('steps')
            ->where('status', ApprovalStatus::Pending)
            ->where('requester_user_id', '!=', $user->id)
            ->orderBy('id')
            ->get()
            ->filter(fn (ApprovalRequest $r) => $this->canDecideNow($user, $r))
            ->map(fn (ApprovalRequest $r): int => $r->id)
            ->all());
    }

    public function pendingCountFor(User $user): int
    {
        return count($this->pendingIdsFor($user));
    }

    /**
     * Batasi query ke tab yang dipilih. Pemanggil sudah memastikan tab boleh dibuka (tabAllowed).
     *
     * @param  Builder<ApprovalRequest>  $query
     * @return Builder<ApprovalRequest>
     */
    public function constrainTab(Builder $query, User $user, string $tab): Builder
    {
        return match ($tab) {
            self::TAB_SUBMITTED => $query->where('approval_requests.requester_user_id', $user->id),
            self::TAB_ALL => $this->constrainScope($query, $user),
            default => $query->whereIn('approval_requests.id', $this->pendingIdsFor($user) ?: [0]),
        };
    }

    /**
     * Versi SQL dari ApproverResolver::inScope (tab `all`), ditambah permintaan pengguna sendiri.
     *
     * @param  Builder<ApprovalRequest>  $query
     * @return Builder<ApprovalRequest>
     */
    public function constrainScope(Builder $query, User $user): Builder
    {
        if (ScopeResolver::for($user) === Scope::All) {
            return $query;
        }

        $employees = $this->scope->visibleEmployeeIds($user) ?? [];
        $locations = $this->locations->visibleIds($user) ?? [];

        return $query->where(function (Builder $q) use ($user, $employees, $locations) {
            $q->where('approval_requests.requester_user_id', $user->id)
                ->orWhereIn('approval_requests.scope_employee_id', $employees ?: [0])
                ->orWhere(fn (Builder $l) => $l->whereNull('approval_requests.scope_employee_id')
                    ->whereIn('approval_requests.scope_location_id', $locations ?: [0]));
        });
    }
}
