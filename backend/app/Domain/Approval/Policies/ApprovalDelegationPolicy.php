<?php

namespace App\Domain\Approval\Policies;

use App\Domain\Approval\Models\ApprovalDelegation;
use App\Models\User;

/**
 * Delegasi (15 §5): pengguna mengelola delegasi MILIKNYA (sebagai pemberi); pemegang
 * `approvals.configure` mengelola semua. Penerima delegasi hanya melihat.
 */
class ApprovalDelegationPolicy
{
    public static function sees(User $user, ApprovalDelegation $delegation): bool
    {
        return $user->can('approvals.configure')
            || $delegation->delegator_user_id === $user->id
            || $delegation->delegate_user_id === $user->id;
    }

    public function revoke(User $user, ApprovalDelegation $delegation): bool
    {
        return $delegation->is_active
            && ($delegation->delegator_user_id === $user->id || $user->can('approvals.configure'));
    }

    /** Membuat delegasi atas nama pengguna lain hanya untuk pemegang `approvals.configure`. */
    public static function mayCreateFor(User $user, int $delegatorUserId): bool
    {
        return $delegatorUserId === $user->id || $user->can('approvals.configure');
    }
}
