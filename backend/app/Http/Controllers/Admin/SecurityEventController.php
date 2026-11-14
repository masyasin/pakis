<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Listings\SecurityEventListing;
use App\Domain\Audit\Models\SecurityEvent;
use App\Http\Controllers\Controller;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Time\DisplayTime;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Log keamanan (05 §4.4, FR-020). Izin dicek middleware route `can:audit.*`. */
class SecurityEventController extends Controller
{
    #[UsesListing(SecurityEventListing::class)]
    public function index(Request $request): Response
    {
        $listing = new SecurityEventListing;
        $result = ListingQuery::apply($listing->exportQuery($request->user()), $listing, $request)
            ->through(fn (SecurityEvent $e) => [
                'id' => $e->id,
                'occurred_at' => DisplayTime::toLocal($e->occurred_at)->translatedFormat('j M Y H.i.s'),
                'event' => $e->event->value,
                'event_label' => $e->event->label(),
                'user' => $e->user?->name,
                'identifier' => $e->identifier,
                'channel' => $e->channel->label(),
                'ip' => $e->ip,
            ]);

        return Inertia::render('security-events/index', [
            ...$result->toInertia(),
            'canExport' => $request->user()?->can('audit.export') ?? false,
        ]);
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'security_events', $request->user());
    }
}
