<?php

namespace App\Domain\Notification\Notifications;

use App\Domain\Notification\Channels\ExternalChannel;
use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\NotificationRouter;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Dasar semua notifikasi ke pengguna (BR-070). Notifikasi aplikasi (`database`) SELALU dibuat dan
 * langsung (koneksi `sync`) agar lonceng segera bertambah; kanal luar lewat antrean lewat
 * ExternalChannel, yang menerapkan kanal per jenis, kuota, jeda, dan cadangan.
 *
 * Teks judul/isi tidak boleh memuat password, token, OTP, atau tautan aksi sensitif: teks yang sama
 * dikirim ke Telegram/WhatsApp dan disimpan di tabel `notifications`.
 */
abstract class PakisNotification extends Notification implements ShouldQueue
{
    use Queueable;

    abstract public function kind(): NotificationKind;

    abstract public function title(): string;

    abstract public function body(): string;

    /** Tautan relatif di aplikasi web, mis. `/unduhan`. */
    public function url(): ?string
    {
        return null;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable instanceof User && app(NotificationRouter::class)->channelsFor($this->kind(), $notifiable) !== []) {
            $channels[] = ExternalChannel::class;
        }

        return $channels;
    }

    /**
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->kind()->value;
    }

    /**
     * @return array{title: string, body: string, url: string|null}
     */
    public function toDatabase(object $notifiable): array
    {
        return ['title' => $this->title(), 'body' => $this->body(), 'url' => $this->url()];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title())
            ->greeting('Halo,')
            ->line($this->body());

        $url = $this->absoluteUrl();

        return $url !== null ? $message->action('Buka', $url) : $message;
    }

    /** Teks polos untuk Telegram & WhatsApp (tanpa Markdown/HTML). */
    public function toText(object $notifiable): string
    {
        $text = setting('general.app_name').' · '.$this->title()."\n".$this->body();
        $url = $this->absoluteUrl();

        return $url !== null ? $text."\n".$url : $text;
    }

    /** Dari APP_URL, bukan host request: kanal luar dikirim dari job antrean. */
    protected function absoluteUrl(): ?string
    {
        $url = $this->url();

        return $url === null ? null : rtrim((string) config('app.url'), '/').$url;
    }
}
