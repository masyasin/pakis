<?php

namespace App\Domain\Settings\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email uji dari halaman Pengaturan (05 §4.3): memastikan SMTP & nama pengirim berfungsi.
 */
class TestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), (string) setting('notification.mail_from_name')),
            subject: 'Email uji '.setting('general.app_name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>Email uji dari <strong>'.e((string) setting('general.app_name')).'</strong>.</p>'
                .'<p>Bila Anda menerima pesan ini, pengiriman email berfungsi.</p>',
        );
    }
}
