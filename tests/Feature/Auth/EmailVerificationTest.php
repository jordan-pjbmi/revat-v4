<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;

beforeEach(function () {
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response('', 200),
    ]);
});

it('sends verification email on registration', function () {
    Notification::fake();

    Volt::test('auth.register')
        ->set('name', 'Test User')
        ->set('email', 'verify@example.com')
        ->set('password', 'securepassword123')
        ->set('password_confirmation', 'securepassword123')
        ->call('register');

    $user = User::where('email', 'verify@example.com')->first();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('shows verification notice page to unverified user', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertOk()
        ->assertSee('Verify your email');
});

it('verifies user when clicking verification link', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
    );

    $this->actingAs($user)
        ->get($verificationUrl)
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('resends verification email via Volt component', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user);

    Volt::test('auth.verify-email')
        ->call('resend')
        ->assertSet('sent', true);

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('rate limits resend verification requests', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user);

    for ($i = 0; $i < 6; $i++) {
        $this->withoutMiddleware(PreventRequestForgery::class)
            ->post(route('verification.send'));
    }

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('verification.send'))
        ->assertStatus(429);
});
