<?php

namespace App\Domain\Notification\Actions;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/** Tandai semua notifikasi milik sendiri yang belum dibaca. Mengembalikan jumlah yang ditandai. */
class MarkAllNotificationsRead
{
    public function handle(User $user): int
    {
        return DatabaseNotification::query()
            ->whereMorphedTo('notifiable', $user)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
