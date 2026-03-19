<?php

namespace App\Services;

use App\Mail\WaitlistConfirmedMail;
use App\Mail\WaitlistVerificationMail;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class WaitlistService
{
    private const COOLDOWN_MINUTES = 5;

    public function join(string $email): ?WaitlistEntry
    {
        $existing = WaitlistEntry::where('email', $email)->first();

        if ($existing && $existing->isVerified()) {
            return null;
        }

        if ($existing && $existing->updated_at->addMinutes(self::COOLDOWN_MINUTES)->isFuture()) {
            return $existing;
        }

        $plaintextToken = Str::random(64);
        $tokenHash = hash('sha256', $plaintextToken);

        if ($existing) {
            $existing->update(['verification_token_hash' => $tokenHash]);
            $existing->plaintext_token = $plaintextToken;
            $this->sendVerificationEmail($existing);

            return $existing;
        }

        $entry = WaitlistEntry::create([
            'email' => $email,
            'verification_token_hash' => $tokenHash,
        ]);

        $entry->plaintext_token = $plaintextToken;
        $this->sendVerificationEmail($entry);

        return $entry;
    }

    public function verify(string $plaintextToken): bool
    {
        $entry = WaitlistEntry::unverified()
            ->where('verification_token_hash', hash('sha256', $plaintextToken))
            ->first();

        if (! $entry) {
            return false;
        }

        $entry->update([
            'verified_at' => now(),
            'verification_token_hash' => null,
        ]);

        Mail::send(new WaitlistConfirmedMail($entry->email));

        return true;
    }

    private function sendVerificationEmail(WaitlistEntry $entry): void
    {
        $url = url('/waitlist/verify?token=' . $entry->plaintext_token);

        Mail::send(new WaitlistVerificationMail($entry->email, $url));
    }
}
