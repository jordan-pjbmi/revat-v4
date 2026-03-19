<?php

namespace App\Mail;

use App\Models\AlphaInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlphaInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AlphaInvite $invite,
        public string $registrationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [$this->invite->email],
            subject: "You've been invited to try Revat",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.alpha-invite',
        );
    }
}
