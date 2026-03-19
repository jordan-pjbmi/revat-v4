<?php

namespace App\Services;

use App\Mail\AlphaInviteMail;
use App\Models\AlphaInvite;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AlphaInviteService
{
    public function create(string $email): AlphaInvite
    {
        $existing = AlphaInvite::where('email', $email)->first();
        if ($existing) {
            throw new \InvalidArgumentException('An invite already exists for this email');
        }

        $plaintextToken = Str::random(64);

        $invite = AlphaInvite::create([
            'email' => $email,
            'token_hash' => hash('sha256', $plaintextToken),
            'last_sent_at' => now(),
        ]);

        $invite->plaintext_token = $plaintextToken;

        $this->sendEmail($invite);

        return $invite;
    }

    public function findByToken(string $plaintextToken): ?AlphaInvite
    {
        return AlphaInvite::pending()
            ->where('token_hash', hash('sha256', $plaintextToken))
            ->first();
    }

    public function resend(AlphaInvite $invite): AlphaInvite
    {
        $plaintextToken = Str::random(64);

        $invite->update([
            'token_hash' => hash('sha256', $plaintextToken),
            'last_sent_at' => now(),
        ]);

        $invite->plaintext_token = $plaintextToken;

        $this->sendEmail($invite);

        return $invite;
    }

    public function revoke(AlphaInvite $invite): void
    {
        $invite->update(['revoked_at' => now()]);
    }

    public function markRegistered(AlphaInvite $invite): void
    {
        $invite->update(['registered_at' => now()]);
    }

    private function sendEmail(AlphaInvite $invite): void
    {
        $url = url('/register?token=' . $invite->plaintext_token);

        Mail::send(new AlphaInviteMail($invite, $url));
    }
}
