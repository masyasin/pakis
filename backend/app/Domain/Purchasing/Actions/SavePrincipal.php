<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Purchasing\Models\Principal;
use Illuminate\Support\Facades\DB;

/** Tambah/ubah prinsipal (FR-A11). Kode diketik admin (huruf besar), unik termasuk yang terhapus. */
final class SavePrincipal
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data  sudah divalidasi PrincipalRequest (kunci = Principal::AUDITED) */
    public function handle(?Principal $principal, array $data, ?int $userId): Principal
    {
        return DB::transaction(function () use ($principal, $data, $userId) {
            if ($principal === null) {
                $saved = Principal::query()->create([...$data, 'created_by' => $userId, 'updated_by' => $userId]);
                $this->audit->record('principal.created', $saved, null, $saved->only(Principal::AUDITED));

                return $saved;
            }

            $saved = Principal::query()->lockForUpdate()->findOrFail($principal->id);
            $before = $saved->only(Principal::AUDITED);
            $saved->fill($data);
            if ($saved->isDirty()) {
                $saved->updated_by = $userId;
                $saved->save();
                $this->audit->record('principal.updated', $saved, $before, $saved->only(Principal::AUDITED));
            }

            return $saved;
        });
    }
}
