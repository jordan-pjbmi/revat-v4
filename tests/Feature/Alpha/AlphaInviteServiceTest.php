<?php

use App\Models\AlphaInvite;
use App\Mail\AlphaInviteMail;
use App\Services\AlphaInviteService;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->service = app(AlphaInviteService::class);
    Mail::fake();
});

it('creates an alpha invite with hashed token and sends email', function () {
    $invite = $this->service->create('alpha@example.com');

    expect($invite)->toBeInstanceOf(AlphaInvite::class);
    expect($invite->email)->toBe('alpha@example.com');
    expect($invite->token_hash)->toHaveLength(64);
    expect($invite->plaintext_token)->toHaveLength(64);
    expect($invite->plaintext_token)->not->toBe($invite->token_hash);
    expect(hash('sha256', $invite->plaintext_token))->toBe($invite->token_hash);
    expect($invite->last_sent_at)->not->toBeNull();
    expect($invite->registered_at)->toBeNull();
    expect($invite->revoked_at)->toBeNull();

    Mail::assertSent(AlphaInviteMail::class, function ($mail) {
        return $mail->hasTo('alpha@example.com');
    });
});

it('prevents duplicate invites for the same email', function () {
    $this->service->create('alpha@example.com');
    $this->service->create('alpha@example.com');
})->throws(\InvalidArgumentException::class, 'An invite already exists for this email');

it('finds a pending invite by plaintext token', function () {
    $invite = $this->service->create('alpha@example.com');
    $found = $this->service->findByToken($invite->plaintext_token);

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($invite->id);
});

it('returns null for invalid token', function () {
    $found = $this->service->findByToken('invalid-token');

    expect($found)->toBeNull();
});

it('returns null for registered invite token', function () {
    $invite = $this->service->create('alpha@example.com');
    $invite->update(['registered_at' => now()]);

    $found = $this->service->findByToken($invite->plaintext_token);

    expect($found)->toBeNull();
});

it('returns null for revoked invite token', function () {
    $invite = $this->service->create('alpha@example.com');
    $invite->update(['revoked_at' => now()]);

    $found = $this->service->findByToken($invite->plaintext_token);

    expect($found)->toBeNull();
});

it('resends an invite with a new token', function () {
    $invite = $this->service->create('alpha@example.com');
    $oldHash = $invite->token_hash;

    $updated = $this->service->resend($invite);

    expect($updated->token_hash)->not->toBe($oldHash);
    expect($updated->plaintext_token)->not->toBeNull();
    expect(hash('sha256', $updated->plaintext_token))->toBe($updated->token_hash);

    Mail::assertSent(AlphaInviteMail::class, 2);
});

it('revokes a pending invite', function () {
    $invite = $this->service->create('alpha@example.com');
    $this->service->revoke($invite);

    expect($invite->fresh()->revoked_at)->not->toBeNull();
});

it('marks an invite as registered', function () {
    $invite = $this->service->create('alpha@example.com');
    $this->service->markRegistered($invite);

    expect($invite->fresh()->registered_at)->not->toBeNull();
});
