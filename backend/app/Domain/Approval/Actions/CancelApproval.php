<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Models\User;

/** Pengaju membatalkan permintaan `pending` yang belum diputuskan sama sekali (BR-153(9), PA-31). */
final class CancelApproval
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function handle(User $actor, int $requestId): ApprovalRequest
    {
        return $this->engine->cancel($actor, $requestId);
    }
}
