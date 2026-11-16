<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\Contracts\Approvable;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Models\User;

/**
 * Ajukan dokumen ke mesin approval (BR-153(1)–(3)). Dipanggil Action "ajukan" tiap dokumen (PO,
 * opname, kios, ...). Null = langsung disetujui karena tidak ada aturan dan
 * `approval.auto_approve_when_no_rule=true`; tanpa aturan dan setting mati → 422 `APPROVAL_RULE_MISSING`.
 */
final class RequestApproval
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function handle(Approvable $document, User $requester): ?ApprovalRequest
    {
        return $this->engine->request($document, $requester);
    }
}
