<?php

namespace App\Domain\Approval\Events;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Models\ApprovalStep;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Satu langkah menjadi aktif (15 §11.2, BR-153(3)) → notifikasi ke calon penyetujunya. `$step->escalated`
 * true berarti langkah langsung tereskalasi karena tidak ada calon (BR-153(4)(5), PA-28). Setelah commit.
 */
final class ApprovalStepActivated
{
    use Dispatchable;

    public function __construct(public readonly ApprovalRequest $request, public readonly ApprovalStep $step) {}
}
