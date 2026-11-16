<?php

namespace App\Domain\Approval\Events;

use App\Domain\Approval\Models\ApprovalRequest;
use Illuminate\Foundation\Events\Dispatchable;

/** Permintaan selesai: disetujui di langkah terakhir atau ditolak (15 §11.2). Setelah commit. */
final class ApprovalDecided
{
    use Dispatchable;

    public function __construct(public readonly ApprovalRequest $request) {}
}
