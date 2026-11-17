<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\AuditLogger;
use App\Domain\Pricing\Approvables\PromotionActivate;
use App\Domain\Pricing\Enums\PromotionStatus;
use App\Domain\Pricing\Models\Promotion;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ajukan promo draf (15 §6.6, BR-169, PA-60): pemegang `promotions.approve` langsung mengaktifkan
 * (tidak perlu menyetujui promonya sendiri); selain itu lewat mesin approval `promotion_activate`
 * (nominal = anggaran). Promo yang periodenya sudah selesai tidak bisa diajukan.
 */
final class SubmitPromotion
{
    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Promotion $promotion, User $actor): Promotion
    {
        return DB::transaction(function () use ($promotion, $actor) {
            $locked = PromotionActivate::query()->lockForUpdate()->findOrFail($promotion->id);
            if ($locked->status !== PromotionStatus::Draft) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Hanya promo draf yang bisa diajukan.');
            }
            if ($locked->ends_at->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['ends_at' => 'Periode promo sudah selesai. Ubah waktu selesai lalu ajukan lagi.']);
            }

            $before = $locked->audited();
            $locked->forceFill(['status' => PromotionStatus::PendingApproval, 'updated_by' => $actor->id])->save();
            $this->audit->record('promotion.submitted', $locked, $before, $locked->audited());

            if ($actor->can('promotions.approve')) {
                $locked->onApproved(null);

                return $locked;
            }

            $request = $this->engine->request($locked, $actor);
            if ($request !== null) {
                $locked->forceFill(['approval_request_id' => $request->id])->save();
            }

            return $locked->refresh();
        });
    }
}
