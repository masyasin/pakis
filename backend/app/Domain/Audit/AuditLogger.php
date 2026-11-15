<?php

namespace App\Domain\Audit;

use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use App\Support\Api\RequestId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Satu-satunya pintu menulis audit (SEC-040, 09 §7.1). Dipanggil EKSPLISIT dari Action di dalam
 * transaksinya (bukan observer otomatis), supaya event yang tercatat bermakna bisnis
 * (`employee.offboarded`, bukan sekadar `updated`) dan ikut batal bila transaksi gagal.
 *
 *     $audit->record('employee.updated', $employee, $before, $employee->only(array_keys($before)));
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(
        string $event,
        ?Model $auditable = null,
        ?array $old = null,
        ?array $new = null,
        ?string $actorLabel = null,
    ): AuditLog {
        // Bila keduanya ada, simpan hanya kunci yang benar-benar berubah: diff ringkas & mudah dibaca.
        if ($old !== null && $new !== null) {
            $changed = array_keys(array_filter($new, fn ($value, $key) => ! array_key_exists($key, $old) || $old[$key] !== $value, ARRAY_FILTER_USE_BOTH));
            $old = array_intersect_key($old, array_flip($changed));
            $new = array_intersect_key($new, array_flip($changed));
        }

        $actor = request()->user();

        $log = new AuditLog([
            'occurred_at' => now(),
            'user_id' => $actor?->getAuthIdentifier(),
            // SEC-009: semua aksi Super Admin (vendor Sintech) ditandai agar mudah ditinjau Owner.
            'actor_label' => $actorLabel ?? ($actor instanceof User && $actor->hasRole(RoleCatalog::SUPER_ADMIN) ? 'vendor' : null),
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => Redactor::redact($old),
            'new_values' => Redactor::redact($new),
            'ip' => request()->ip(),
            'user_agent' => self::userAgent(request()),
            'request_id' => RequestId::current(),
        ]);
        $log->save();

        return $log;
    }

    public static function userAgent(Request $request): ?string
    {
        $agent = $request->userAgent();

        return $agent === null ? null : Str::limit($agent, 252, '...');
    }
}
