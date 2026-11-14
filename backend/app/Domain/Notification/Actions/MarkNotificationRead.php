<?php

namespace App\Domain\Notification\Actions;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Tandai satu notifikasi milik sendiri sebagai dibaca. Notifikasi orang lain tidak ditemukan
 * (null → controller menjawab 404, SEC-033), bukan ditolak, agar keberadaannya tidak terungkap.
 */
class MarkNotificationRead
{
    public function handle(User $user, string $id): ?DatabaseNotification
    {
        $notification = DatabaseNotification::query()
            ->whereMorphedTo('notifiable', $user)
            ->whereKey($id)
            ->first();

        if ($notification !== null && $notification->read_at === null) {
            $notification->markAsRead();
        }

        return $notification;
    }
}
