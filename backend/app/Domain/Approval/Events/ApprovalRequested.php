<?php

namespace App\Domain\Approval\Events;

use App\Domain\Approval\Models\ApprovalRequest;
use Illuminate\Foundation\Events\Dispatchable;

/** Permintaan approval baru dibuat (15 §11.2). Dipancarkan SETELAH transaksi commit. */
final class ApprovalRequested
{
    use Dispatchable;

    public function __construct(public readonly ApprovalRequest $request) {}
}
