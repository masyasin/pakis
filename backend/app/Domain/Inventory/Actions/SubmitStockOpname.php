<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Approvables\OpnameVarianceApproval;
use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Support\OpnameVariance;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Time\DisplayTime;
use Illuminate\Validation\ValidationException;

/**
 * Ajukan posting dari `review` (rute `admin.stock-opnames.post` "/posting", 15 §6.6, PA-67).
 * Tanpa selisih → posting langsung (tanggal bisnis = hari ini). Ada selisih → setiap baris selisih
 * wajib alasan (BR-162(5)) dan hitung ulang sesuai ambang; lalu `pending_approval` + approval
 * `stock_opname_variance` (nominal = Σ |selisih| × HPP: < Rp 1 jt satu langkah, ≥ Rp 1 jt dua).
 * Setelah pernah DITOLAK, setiap baris selisih wajib dihitung ulang orang lain (keputusan Mas).
 */
final class SubmitStockOpname
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ApprovalEngine $engine,
        private readonly PostStockOpname $post,
    ) {}

    public function handle(StockOpname $opname, User $actor): StockOpname
    {
        return StockLedger::transaction(function () use ($opname, $actor) {
            $locked = OpnameVarianceApproval::query()->lockForUpdate()->findOrFail($opname->id);
            if ($locked->status !== StockOpnameStatus::Review) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Hanya opname yang sedang ditinjau yang bisa diposting.');
            }
            $locked->setRelation('lines', $locked->lines()->get());
            $variances = OpnameVariance::for($locked);
            $withVariance = $locked->lines->filter(fn ($l) => OpnameVariance::hasVariance($variances[$l->id]));

            if ($withVariance->isEmpty()) {
                return $this->post->handle($locked, DisplayTime::toLocal(now())->toDateString(), $actor->id);
            }

            $noReason = $withVariance->filter(fn ($l) => $l->variance_reason_id === null)->count();
            if ($noReason > 0) {
                throw ValidationException::withMessages(['lines' => "Isi alasan selisih untuk {$noReason} baris."]);
            }
            $rejectedBefore = ApprovalRequest::query()->where('document_type', OpnameVarianceApproval::DOCUMENT_TYPE)
                ->where('document_id', $locked->id)->where('status', ApprovalStatus::Rejected)->exists();
            FinishCounting::assertRecounted($locked, $variances, all: $rejectedBefore);

            $before = $locked->audited();
            $locked->forceFill([
                'status' => StockOpnameStatus::PendingApproval,
                'variance_value' => (string) OpnameVariance::totalValue($locked),
                'updated_by' => $actor->id,
            ])->save();
            $this->audit->record('stock_opname.submitted', $locked, $before, $locked->audited());

            // Tanpa aturan + auto approve → onApproved langsung memposting (request null).
            $request = $this->engine->request($locked, $actor);
            if ($request !== null) {
                $locked->forceFill(['approval_request_id' => $request->id])->save();
            }

            return $locked->refresh();
        });
    }
}
