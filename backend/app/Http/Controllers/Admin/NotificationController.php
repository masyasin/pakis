<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Notification\Actions\MarkAllNotificationsRead;
use App\Domain\Notification\Actions\MarkNotificationRead;
use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Listings\NotificationListing;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Time\DisplayTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notifikasi milik sendiri (05 §4.2, BR-070). Tidak ada id pengguna di URL; notifikasi orang lain
 * selalu 404 (SEC-033).
 */
class NotificationController extends Controller
{
    #[UsesListing(NotificationListing::class)]
    public function index(Request $request): Response
    {
        $query = DatabaseNotification::query()->whereMorphedTo('notifiable', self::user($request));

        $result = ListingQuery::apply($query, new NotificationListing, $request)
            ->through(function (DatabaseNotification $n) {
                $data = (array) $n->data;

                return [
                    'id' => $n->id,
                    'type' => $n->type,
                    'type_label' => NotificationKind::tryFrom((string) $n->type)?->label() ?? 'Lainnya',
                    'title' => (string) ($data['title'] ?? ''),
                    'body' => (string) ($data['body'] ?? ''),
                    'url' => self::safePath($data['url'] ?? null),
                    'read' => $n->read_at !== null,
                    'created_at' => $n->created_at ? DisplayTime::toLocal($n->created_at)->translatedFormat('j M Y H.i') : null,
                ];
            });

        return Inertia::render('notifications/index', $result->toInertia());
    }

    /** Tandai dibaca; `open=1` lalu menuju tautan notifikasi (hanya path internal). */
    public function read(Request $request, string $id, MarkNotificationRead $action): RedirectResponse
    {
        $notification = $action->handle(self::user($request), $id);
        abort_if($notification === null, 404);

        $url = self::safePath(((array) $notification->data)['url'] ?? null);

        return $request->boolean('open') && $url !== null ? redirect($url) : back();
    }

    public function readAll(Request $request, MarkAllNotificationsRead $action): RedirectResponse
    {
        $action->handle(self::user($request));

        return back();
    }

    /** Dipolling lonceng tiap 60 detik (07 §1.2). */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = DatabaseNotification::query()
            ->whereMorphedTo('notifiable', self::user($request))
            ->whereNull('read_at')
            ->count();

        return response()->json(['count' => $count]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Hanya path relatif internal (`/unduhan`), bukan `//host` atau URL penuh: mencegah open redirect
     * bila isi notifikasi kelak berasal dari data yang bisa dipengaruhi pengguna.
     */
    private static function safePath(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^/(?!/)[A-Za-z0-9/_\-?=&.%]*$#', $url) === 1 ? $url : null;
    }
}
