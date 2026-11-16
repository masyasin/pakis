<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Models\User;

/**
 * Batalkan permintaan `pending`: pengaju sebelum ada keputusan (BR-153(9), PA-31), atau pemegang
 * `approvals.configure` kapan pun dengan alasan wajib (PA-38). Aturan lengkap di ApprovalEngine::cancel.
 */
final class CancelApproval
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function handle(User $actor, int $requestId, ?string $note = null): ApprovalRequest
    {
        return $this->engine->cancel($actor, $requestId, $note);
    }
}
