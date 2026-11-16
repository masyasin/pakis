<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Models\User;

/**
 * Setujui/tolak langkah aktif (BR-153, SEC-101). `$passwordConfirmed` =
 * `PasswordConfirmation::isRecent()` dari controller; mesin yang memutuskan apakah nominal permintaan
 * ini mewajibkannya (`approval.reauth_amount`), karena syaratnya bergantung nominal, bukan rute.
 */
final class DecideApprovalStep
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function approve(User $actor, int $requestId, int $step, ?string $note, bool $passwordConfirmed): ApprovalRequest
    {
        return $this->engine->decide($actor, $requestId, $step, true, $note, $passwordConfirmed);
    }

    public function reject(User $actor, int $requestId, int $step, string $note, bool $passwordConfirmed): ApprovalRequest
    {
        return $this->engine->decide($actor, $requestId, $step, false, $note, $passwordConfirmed);
    }
}
