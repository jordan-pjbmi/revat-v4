<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invitation $invitation,
        public string $acceptUrl,
    ) {}

    public function envelope(): Envelope
    {
        $orgName = $this->invitation->organization->name;

        return new Envelope(
            to: [$this->invitation->email],
            subject: "You've been invited to join {$orgName} on Revat",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.invitation',
        );
    }
}
