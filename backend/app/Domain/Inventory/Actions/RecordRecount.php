<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Models\ApprovalStep;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Approvables\OpnameVarianceApproval;
use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockOpnameLine;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hitung ulang satu baris oleh pengguna BERBEDA dari penghitung pertama (BR-162(4), SEC-103).
 * Boleh selama `counting` (baris lewat ambang) dan `review` (setelah selisih ditolak, PA-67).
 * Hasil hitung ulang menjadi hitung final baris itu.
 */
final class RecordRecount
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(StockOpname $opname, StockOpnameLine $line, Qty $qty, ?string $note, int $userId): StockOpnameLine
    {
        return DB::transaction(function () use ($opname, $line, $qty, $note, $userId) {
            $locked = StockOpname::query()->lockForUpdate()->findOrFail($opname->id);
            if (! in_array($locked->status, [StockOpnameStatus::Counting, StockOpnameStatus::Review], true)) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Opname ini tidak sedang menerima hitung ulang.');
            }
            // Saat tinjau angka sistem sudah terlihat: hitung ulang di sana bisa "menghapus" selisih tanpa
            // approval. Karena itu hanya dibuka setelah selisih DITOLAK (keputusan Mas, PA-67 b).
            if ($locked->status === StockOpnameStatus::Review && ! self::rejectedBefore($locked)) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value],
                    'Hitung ulang saat tinjau hanya dibuka setelah selisih ditolak penyetuju.');
            }
            $row = StockOpnameLine::query()->where('stock_opname_id', $locked->id)->lockForUpdate()->findOrFail($line->id);
            if ($row->qty_counted_base === null) {
                throw ValidationException::withMessages(['qty' => 'Baris ini belum dihitung pertama kali.']);
            }
            if ($row->counted_by === $userId) {
                throw ValidationException::withMessages(['qty' => 'Hitung ulang harus dilakukan orang lain, bukan penghitung pertama.']);
            }
            // Penyetuju yang menolak (termasuk yang diwakili) tidak boleh lalu "menghapus" selisih lewat
            // hitung ulangnya sendiri: hitung ulang setelah ditolak harus orang ketiga (SEC-103).
            if ($locked->status === StockOpnameStatus::Review && in_array($userId, self::rejectedDeciders($locked), true)) {
                throw ValidationException::withMessages(['qty' => 'Penyetuju yang menolak selisih ini tidak boleh menghitung ulang.']);
            }

            $before = ['line_id' => $row->id, 'qty_recount_base' => $row->qty_recount_base, 'recounted_by' => $row->recounted_by];
            $row->forceFill([
                'qty_recount_base' => (string) $qty,
                'recounted_by' => $userId,
                'recounted_at' => now(),
                'note' => $note ?? $row->note,
            ])->save();
            $this->audit->record('stock_opname.recounted', $locked, $before,
                ['line_id' => $row->id, 'qty_recount_base' => (string) $qty, 'recounted_by' => $userId]);

            return $row;
        });
    }

    /**
     * Pengguna yang memutuskan (atau diwakili) di permintaan selisih yang DITOLAK.
     *
     * @return list<int>
     */
    public static function rejectedDeciders(StockOpname $opname): array
    {
        $requests = ApprovalRequest::query()->where('document_type', OpnameVarianceApproval::DOCUMENT_TYPE)
            ->where('document_id', $opname->id)->where('status', ApprovalStatus::Rejected)->pluck('id');

        return array_values(array_unique(ApprovalStep::query()->whereIn('approval_request_id', $requests)->get(['decided_by', 'acting_for_user_id'])
            ->flatMap(fn (ApprovalStep $s) => array_filter([$s->decided_by, $s->acting_for_user_id], fn (?int $id) => $id !== null))->all()));
    }

    /** Pernah ada permintaan approval selisih opname ini yang ditolak. */
    public static function rejectedBefore(StockOpname $opname): bool
    {
        return ApprovalRequest::query()->where('document_type', OpnameVarianceApproval::DOCUMENT_TYPE)
            ->where('document_id', $opname->id)->where('status', ApprovalStatus::Rejected)->exists();
    }
}
