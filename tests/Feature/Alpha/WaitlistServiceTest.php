<?php

use App\Models\WaitlistEntry;
use App\Mail\WaitlistVerificationMail;
use App\Mail\WaitlistConfirmedMail;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->service = app(WaitlistService::class);
    Mail::fake();
});

it('creates a waitlist entry and sends verification email for new email', function () {
    $entry = $this->service->join('visitor@example.com');

    expect($entry)->toBeInstanceOf(WaitlistEntry::class);
    expect($entry->email)->toBe('visitor@example.com');
    expect($entry->verification_token_hash)->toHaveLength(64);
    expect($entry->verified_at)->toBeNull();

    Mail::assertSent(WaitlistVerificationMail::class, function ($mail) {
        return $mail->hasTo('visitor@example.com');
    });
});

it('resends verification for unverified email outside cooldown', function () {
    $entry = $this->service->join('visitor@example.com');

    // Simulate 6 minutes passing (use DB directly to bypass Eloquent auto-timestamps)
    \Illuminate\Support\Facades\DB::table('waitlist_entries')
        ->where('id', $entry->id)
        ->update(['updated_at' => now()->subMinutes(6)]);

    $this->service->join('visitor@example.com');

    Mail::assertSent(WaitlistVerificationMail::class, 2);
});

it('does not resend verification for unverified email within cooldown', function () {
    $this->service->join('visitor@example.com');
    $this->service->join('visitor@example.com');

    // Only one email sent (the first join)
    Mail::assertSent(WaitlistVerificationMail::class, 1);
});

it('returns null and sends no email for already verified email', function () {
    WaitlistEntry::factory()->verified()->create(['email' => 'visitor@example.com']);

    $result = $this->service->join('visitor@example.com');

    expect($result)->toBeNull();
    Mail::assertNothingSent();
});

it('verifies a waitlist entry by token and sends confirmation', function () {
    $entry = $this->service->join('visitor@example.com');
    $token = $entry->plaintext_token;

    $verified = $this->service->verify($token);

    expect($verified)->toBeTrue();

    $entry->refresh();
    expect($entry->verified_at)->not->toBeNull();
    expect($entry->verification_token_hash)->toBeNull();

    Mail::assertSent(WaitlistConfirmedMail::class, function ($mail) {
        return $mail->hasTo('visitor@example.com');
    });
});

it('returns false for invalid verification token', function () {
    $result = $this->service->verify('invalid-token');

    expect($result)->toBeFalse();
});

it('returns false for already verified token', function () {
    $entry = $this->service->join('visitor@example.com');
    $token = $entry->plaintext_token;

    $this->service->verify($token);
    $result = $this->service->verify($token);

    expect($result)->toBeFalse();
});
