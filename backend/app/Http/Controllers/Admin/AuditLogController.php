<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Listings\AuditLogListing;
use App\Domain\Audit\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Time\DisplayTime;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Audit log (05 §4.4, FR-019). Izin dicek middleware route `can:audit.*`. */
class AuditLogController extends Controller
{
    #[UsesListing(AuditLogListing::class)]
    public function index(Request $request): Response
    {
        $listing = new AuditLogListing;
        $result = ListingQuery::apply($listing->exportQuery($request->user()), $listing, $request)
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'occurred_at' => DisplayTime::toLocal($log->occurred_at)->translatedFormat('j M Y H.i.s'),
                'actor' => $log->user->name ?? $log->actor_label ?? 'sistem',
                'event' => $log->event,
                'object' => self::objectLabel($log),
                'ip' => $log->ip,
            ]);

        return Inertia::render('audit/index', [
            ...$result->toInertia(),
            'canExport' => $request->user()?->can('audit.export') ?? false,
        ]);
    }

    public function show(AuditLog $log): Response
    {
        $old = $log->old_values ?? [];
        $new = $log->new_values ?? [];
        $keys = array_values(array_unique([...array_keys($old), ...array_keys($new)]));

        return Inertia::render('audit/show', [
            'log' => [
                'id' => $log->id,
                'occurred_at' => DisplayTime::toLocal($log->occurred_at)->translatedFormat('l, j F Y H.i.s'),
                'actor' => $log->user->name ?? $log->actor_label ?? 'sistem',
                'event' => $log->event,
                'object' => self::objectLabel($log),
                'ip' => $log->ip,
                'user_agent' => $log->user_agent,
                'request_id' => $log->request_id,
            ],
            'changes' => array_map(fn (string $key) => [
                'key' => $key,
                'old' => self::display($old[$key] ?? null),
                'new' => self::display($new[$key] ?? null),
                'changed' => ($old[$key] ?? null) !== ($new[$key] ?? null),
            ], $keys),
        ]);
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'audit_logs', $request->user());
    }

    private static function objectLabel(AuditLog $log): ?string
    {
        return $log->auditable_type === null ? null : class_basename($log->auditable_type).'#'.$log->auditable_id;
    }

    /** Nilai untuk tampilan: skalar apa adanya, struktur sebagai JSON rapi. */
    private static function display(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
