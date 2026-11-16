<?php

namespace App\Domain\Approval;

use App\Domain\Access\DataScope;
use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Access\Support\ScopeResolver;
use App\Domain\Access\Support\WebAccess;
use App\Domain\Approval\Enums\ApproverType;
use App\Domain\Approval\Models\ApprovalDelegation;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Models\ApprovalStep;
use App\Domain\Approval\Support\DecisionRight;
use App\Domain\Attendance\Support\CorrectionAuthority;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Support\LocationScope;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Illuminate\Support\Collection;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Siapa calon penyetuju sebuah langkah (BR-153(3)–(7), (11), PA-28, PA-29). Satu pintu untuk mesin
 * (keputusan), kotak masuk, notifikasi, dan pratinjau rantai, agar "siapa yang boleh" tidak
 * dihitung berbeda di tempat berbeda.
 *
 * Calon LANGSUNG (bukan lewat delegasi) = pengguna aktif yang bukan pengaju/pembuat dokumen, belum
 * memutuskan langkah lain di permintaan itu, dan:
 *   - `permission`: memegang izin langkah DAN dokumen dalam scope-nya;
 *   - `direct_supervisor`/`location_head`: akun pegawai `resolved_employee_id`;
 *   - langkah tereskalasi: juga pemegang `escalation_permission` (kosong = `approvals.configure`)
 *     dalam scope.
 * Delegasi: delegasi aktif hari ini (tanggal lokal) dari calon langsung, untuk jenis dokumen itu.
 * Tidak berantai: hak delegasi hanya dari calon LANGSUNG pemberi, bukan dari delegasi yang ia terima.
 *
 * Calon juga wajib `isEligible` (aktif, bukan Super Admin — PA-37, punya akses web — PA-36).
 *
 * Izin dibaca dari role Spatie (`hasPermissionTo`), bukan Gate: Gate::before meloloskan Super Admin
 * untuk semua ability, padahal maker-checker harus berlaku untuk siapa pun (SEC-101).
 */
final class ApproverResolver
{
    /** Fallback eskalasi bila langkah tidak menyebut `escalation_permission` (PA-28). */
    public const FALLBACK_PERMISSION = 'approvals.configure';

    public function __construct(
        private readonly DataScope $scope,
        private readonly LocationScope $locations,
        private readonly CorrectionAuthority $authority,
        private readonly WebAccess $webAccess,
    ) {}

    /**
     * Pegawai penyetuju langkah `direct_supervisor`/`location_head` saat langkah aktif. Null =
     * tidak ada (langkah langsung tereskalasi, BR-153(5)).
     */
    public function resolveEmployee(ApprovalRequest $request, ApproverType $type): ?int
    {
        return match ($type) {
            ApproverType::Permission => null,
            // Penugasan UTAMA hari ini, atasan aktif (BR-153(3), pola koreksi BR-069).
            ApproverType::DirectSupervisor => $request->scope_employee_id === null
                ? null
                : $this->authority->approverFor($request->scope_employee_id),
            ApproverType::LocationHead => $this->locationHead($request->scope_location_id),
        };
    }

    public function escalationPermission(ApprovalRequest $request, ApprovalStep $step): string
    {
        $permission = $request->snapshotStep($step->step_order)['escalation_permission'] ?? null;

        return is_string($permission) && $permission !== '' ? $permission : self::FALLBACK_PERMISSION;
    }

    /**
     * Pengguna yang sudah memutuskan langkah LAIN di permintaan ini, langsung maupun sebagai pemberi
     * delegasi (BR-153(11): satu pengguna satu langkah).
     *
     * @return list<int>
     */
    public function decidersOtherThan(ApprovalRequest $request, ApprovalStep $step): array
    {
        $ids = [];
        foreach ($request->steps as $other) {
            if ($other->step_order === $step->step_order) {
                continue;
            }
            foreach ([$other->decided_by, $other->acting_for_user_id] as $id) {
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Akun yang boleh menjadi penyetuju dokumen bisnis sama sekali: aktif, bukan Super Admin vendor
     * (PA-37), dan bisa membuka web admin (PA-36, BR-017 `WebAccess`). Akun yang gagal syarat ini
     * tidak dihitung calon, sehingga langkah yang hanya punya calon seperti itu langsung tereskalasi.
     */
    public function isEligible(User $user): bool
    {
        return $user->is_active
            && ! $user->hasRole(RoleCatalog::SUPER_ADMIN)
            && $this->webAccess->allows($user);
    }

    public function isDirectCandidate(User $user, ApprovalRequest $request, ApprovalStep $step): bool
    {
        if (! $this->isEligible($user) || $request->isMaker($user->id) || in_array($user->id, $this->decidersOtherThan($request, $step), true)) {
            return false;
        }

        $matches = match ($step->approver_type) {
            ApproverType::Permission => $step->permission_name !== null
                && self::holds($user, $step->permission_name)
                && $this->inScope($user, $request),
            ApproverType::DirectSupervisor, ApproverType::LocationHead => $step->resolved_employee_id !== null
                && $user->employee_id !== null
                && (int) $user->employee_id === $step->resolved_employee_id,
        };

        return $matches || ($step->escalated
            && self::holds($user, $this->escalationPermission($request, $step))
            && $this->inScope($user, $request));
    }

    /**
     * Hak `$user` memutuskan langkah `$step` sekarang: langsung, sebagai delegasi, atau ditolak.
     * `self` = satu-satunya jalan adalah sebagai pengaju/pembuat (atau delegasi mereka) → 403
     * `APPROVAL_SELF_DECISION`, bukan sekadar "bukan giliran".
     */
    public function rightOf(User $user, ApprovalRequest $request, ApprovalStep $step): DecisionRight
    {
        if ($request->isMaker($user->id)) {
            return DecisionRight::selfDecision();
        }
        if ($this->isDirectCandidate($user, $request, $step)) {
            return DecisionRight::direct();
        }
        if (! $this->isEligible($user) || in_array($user->id, $this->decidersOtherThan($request, $step), true)) {
            return DecisionRight::denied();
        }

        $self = false;
        foreach ($this->delegationsTo($user) as $delegation) {
            if (! $delegation->covers($request->document_type) || $delegation->delegator === null) {
                continue;
            }
            if ($request->isMaker($delegation->delegator_user_id)) {
                // Delegasi dari pengaju/pembuat tidak pernah memberi hak (BR-153(4)).
                $self = true;

                continue;
            }
            if ($this->isDirectCandidate($delegation->delegator, $request, $step)) {
                return DecisionRight::delegated($delegation->delegator_user_id);
            }
        }

        return $self ? DecisionRight::selfDecision() : DecisionRight::denied();
    }

    /**
     * Calon langsung langkah ini (tanpa delegasi). Dipakai menentukan eskalasi saat langkah aktif
     * (tidak ada calon → langsung tereskalasi) dan penerima notifikasi.
     *
     * @return Collection<int, User>
     */
    public function directCandidates(ApprovalRequest $request, ApprovalStep $step): Collection
    {
        $users = collect();

        if ($step->approver_type === ApproverType::Permission && $step->permission_name !== null) {
            $users = $users->merge(self::holders($step->permission_name));
        } elseif ($step->resolved_employee_id !== null) {
            $users = $users->merge(User::query()->where('employee_id', $step->resolved_employee_id)->where('is_active', true)->get());
        }
        if ($step->escalated) {
            $users = $users->merge(self::holders($this->escalationPermission($request, $step)));
        }

        return $users->unique('id')
            ->filter(fn (User $u) => $this->isDirectCandidate($u, $request, $step))
            ->values();
    }

    /**
     * Calon langsung + delegasi aktif mereka (penerima notifikasi `approval_requested`, 15 §9.1).
     *
     * @return Collection<int, User>
     */
    public function candidatesWithDelegates(ApprovalRequest $request, ApprovalStep $step): Collection
    {
        $direct = $this->directCandidates($request, $step);
        if ($direct->isEmpty()) {
            return $direct;
        }

        $delegateIds = ApprovalDelegation::query()
            ->activeOn(self::today())
            ->whereIn('delegator_user_id', $direct->pluck('id')->all())
            ->get()
            ->filter(fn (ApprovalDelegation $d) => $d->covers($request->document_type))
            ->pluck('delegate_user_id')
            ->all();

        $delegates = $delegateIds === [] ? collect() : User::query()->whereKey($delegateIds)->get()
            ->filter(fn (User $u) => $this->rightOf($u, $request, $step)->allowed);

        return $direct->merge($delegates)->unique('id')->values();
    }

    /**
     * Dokumen dalam scope pengguna (BR-153(3)): scope `all` = semua; bila ada `scope_employee_id`,
     * pegawai itu terlihat (DataScope); bila hanya `scope_location_id`, lokasi itu terlihat
     * (LocationScope); dokumen tanpa keduanya hanya untuk scope `all` (sama dengan DataScope::allows).
     */
    public function inScope(User $user, ApprovalRequest $request): bool
    {
        if (ScopeResolver::for($user) === Scope::All) {
            return true;
        }
        if ($request->scope_employee_id !== null) {
            return $this->scope->allows($user, $request->scope_employee_id);
        }
        if ($request->scope_location_id !== null) {
            $ids = $this->locations->visibleIds($user);

            return $ids === null || in_array($request->scope_location_id, $ids, true);
        }

        return false;
    }

    /** Izin dari role (DB), aman untuk nama izin yang tidak ada di katalog. */
    public static function holds(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /** @return Collection<int, User> */
    public static function holders(string $permission): Collection
    {
        try {
            return User::permission($permission)->where('is_active', true)->get()->toBase();
        } catch (PermissionDoesNotExist) {
            return collect();
        }
    }

    /** @return Collection<int, ApprovalDelegation> */
    public function delegationsTo(User $user): Collection
    {
        return ApprovalDelegation::query()
            ->with('delegator')
            ->activeOn(self::today())
            ->where('delegate_user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    public static function today(): string
    {
        return DisplayTime::toLocal(now())->toDateString();
    }

    /** Kepala lokasi (PA-29): `locations.head_employee_id`, hanya bila pegawainya aktif. */
    private function locationHead(?int $locationId): ?int
    {
        if ($locationId === null) {
            return null;
        }
        $head = Location::query()->whereKey($locationId)->value('head_employee_id');
        if ($head === null) {
            return null;
        }

        return Employee::query()->whereKey($head)->where('status', EmployeeStatus::Active)->exists() ? (int) $head : null;
    }
}
