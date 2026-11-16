<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notification\Actions\MarkAllNotificationsRead;
use App\Domain\Notification\Actions\MarkNotificationRead;
use App\Domain\Notification\Listings\MyNotificationListing;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Api\RequestId;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Time\DisplayTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notifikasi milik sendiri di Pakis Pro (05 §5.1 `api.v1.notifications.*`, BR-070, T-043). Tidak ada
 * id pengguna dari klien; notifikasi orang lain selalu 404 (SEC-033). Tautan web (`url`) tidak
 * dikirim: path itu hanya bermakna di aplikasi web.
 */
class NotificationController extends Controller
{
    #[UsesListing(MyNotificationListing::class)]
    public function index(Request $request): JsonResponse
    {
        $query = DatabaseNotification::query()->whereMorphedTo('notifiable', self::user($request));

        return response()->json(
            ListingQuery::apply($query, new MyNotificationListing, $request)
                ->through(fn (DatabaseNotification $n) => self::payload($n))
                ->toApi(),
        );
    }

    /** POST /api/v1/notifications/{id}/read → 200 objek notifikasi (sudah dibaca). */
    public function read(Request $request, string $id, MarkNotificationRead $action): JsonResponse
    {
        $notification = $action->handle(self::user($request), $id);
        if ($notification === null) {
            throw new ApiError(ErrorCode::NOT_FOUND);
        }

        return response()->json(['data' => self::payload($notification), 'meta' => ['request_id' => RequestId::current()]]);
    }

    /** POST /api/v1/notifications/read-all → 200 `{ marked }` = jumlah yang baru ditandai. */
    public function readAll(Request $request, MarkAllNotificationsRead $action): JsonResponse
    {
        $marked = $action->handle(self::user($request));

        return response()->json(['data' => ['marked' => $marked], 'meta' => ['request_id' => RequestId::current()]]);
    }

    /** @return array<string, mixed> */
    private static function payload(DatabaseNotification $n): array
    {
        $data = (array) $n->data;

        return [
            'id' => $n->id,
            'type' => $n->type,
            'title' => is_string($data['title'] ?? null) ? $data['title'] : '',
            'body' => is_string($data['body'] ?? null) ? $data['body'] : '',
            'read' => $n->read_at !== null,
            'created_at' => $n->created_at === null ? null : DisplayTime::toLocal($n->created_at)->toIso8601String(),
        ];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new ApiError(ErrorCode::UNAUTHENTICATED);
        }

        return $user;
    }
}
