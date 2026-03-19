<?php

use App\Models\AlphaAgreement;
use App\Models\AlphaInvite;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Mail\WaitlistVerificationMail;
use App\Services\AlphaInviteService;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Mail::fake();
});

// --- Registration with valid token ---

it('shows registration form when visiting /register with valid token', function () {
    $invite = app(AlphaInviteService::class)->create('alpha@example.com');

    $response = $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class)
        ->get('/register?token=' . $invite->plaintext_token);

    $response->assertStatus(200);
    $response->assertSee('alpha@example.com');
    $response->assertSee('Alpha Testing Agreement');
});

it('registers a new user with valid token and agreement', function () {
    $invite = app(AlphaInviteService::class)->create('alpha@example.com');

    Volt::test('auth.register', ['token' => $invite->plaintext_token])
        ->set('name', 'Alpha Tester')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->set('agree_to_terms', true)
        ->call('register')
        ->assertRedirect(route('dashboard'));

    // User created
    expect(User::where('email', 'alpha@example.com')->exists())->toBeTrue();

    // Invite marked registered
    expect($invite->fresh()->registered_at)->not->toBeNull();

    // Agreement recorded
    $agreement = AlphaAgreement::where('email', 'alpha@example.com')->first();
    expect($agreement)->not->toBeNull();
    expect($agreement->agreement_version)->toBe('1.0');
});

it('rejects registration without agreement checkbox', function () {
    $invite = app(AlphaInviteService::class)->create('alpha@example.com');

    Volt::test('auth.register', ['token' => $invite->plaintext_token])
        ->set('name', 'Alpha Tester')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        // agree_to_terms intentionally omitted
        ->call('register')
        ->assertHasErrors(['agree_to_terms']);

    expect(User::where('email', 'alpha@example.com')->exists())->toBeFalse();
});

it('redirects to login if email already has an account', function () {
    $invite = app(AlphaInviteService::class)->create('existing@example.com');
    User::factory()->create(['email' => 'existing@example.com']);

    Volt::test('auth.register', ['token' => $invite->plaintext_token])
        ->set('name', 'Existing User')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->set('agree_to_terms', true)
        ->call('register')
        ->assertRedirect(route('login'));

    expect($invite->fresh()->registered_at)->not->toBeNull();
});

// --- Waitlist (no token) ---

it('shows waitlist form when visiting /register without token', function () {
    $response = $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class)
        ->get('/register');

    $response->assertStatus(200);
    $response->assertSee('Join the Waitlist');
});

it('shows waitlist form with error for invalid token', function () {
    $response = $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class)
        ->get('/register?token=invalid-token');

    $response->assertStatus(200);
    $response->assertSee('This invite link is no longer valid');
    $response->assertSee('Join the Waitlist');
});

it('submits waitlist form and shows success', function () {
    Volt::test('auth.register')
        ->set('waitlist_email', 'visitor@example.com')
        ->call('joinWaitlist');

    expect(WaitlistEntry::where('email', 'visitor@example.com')->exists())->toBeTrue();
    Mail::assertSent(WaitlistVerificationMail::class);
});

// --- Waitlist verify endpoint ---

it('verifies a waitlist entry via GET /waitlist/verify', function () {
    $service = app(\App\Services\WaitlistService::class);
    $entry = $service->join('visitor@example.com');

    $response = $this->get('/waitlist/verify?token=' . $entry->plaintext_token);

    $response->assertStatus(200);
    $response->assertSee('confirmed!');
    expect($entry->fresh()->verified_at)->not->toBeNull();
});

it('shows error for invalid waitlist verify token', function () {
    $response = $this->get('/waitlist/verify?token=bad-token');

    $response->assertStatus(200);
    $response->assertSee('no longer valid');
});
