<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Volt\Volt;

beforeEach(function () {
    // Prevent uncompromised password check from hitting external API
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response('', 200),
    ]);
});

it('renders the login page', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Welcome back');
});

it('authenticates user and redirects to dashboard', function () {
    $user = User::factory()->create([
        'password' => 'password1234',
    ]);

    Volt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'password1234')
        ->call('login')
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('shows deactivation error for deactivated users', function () {
    $user = User::factory()->create([
        'password' => 'password1234',
        'deactivated_at' => now(),
    ]);

    Volt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'password1234')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('shows generic error for invalid credentials', function () {
    User::factory()->create([
        'email' => 'test@example.com',
        'password' => 'password1234',
    ]);

    Volt::test('auth.login')
        ->set('email', 'test@example.com')
        ->set('password', 'wrongpassword')
        ->call('login')
        ->assertHasErrors('email');
});

it('renders the register page', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('Create your account');
});

it('creates user and redirects on registration', function () {
    Volt::test('auth.register')
        ->set('name', 'Test User')
        ->set('email', 'newuser@example.com')
        ->set('password', 'securepassword123')
        ->set('password_confirmation', 'securepassword123')
        ->call('register')
        ->assertRedirect(route('dashboard'));

    $this->assertDatabaseHas('users', ['email' => 'newuser@example.com']);
    $this->assertAuthenticated();
});

it('renders the forgot password page', function () {
    $this->get(route('password.request'))
        ->assertOk()
        ->assertSee('Forgot your password?');
});

it('sends password reset email', function () {
    Notification::fake();

    $user = User::factory()->create();

    Volt::test('auth.forgot-password')
        ->set('email', $user->email)
        ->call('sendResetLink');

    Notification::assertSentTo($user, ResetPassword::class);
});

it('renders the reset password page', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
        ->assertOk()
        ->assertSee('Reset your password');
});

it('resets password and redirects to login', function () {
    $user = User::factory()->create();

    $token = Password::createToken($user);

    Volt::test('auth.reset-password', ['token' => $token])
        ->set('email', $user->email)
        ->set('password', 'newpassword1234')
        ->set('password_confirmation', 'newpassword1234')
        ->call('resetPassword')
        ->assertRedirect(route('login'));

    expect(Hash::check('newpassword1234', $user->fresh()->password))->toBeTrue();
});

it('redirects authenticated users away from guest pages', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('login'))
        ->assertRedirect();

    $this->actingAs($user)
        ->get(route('register'))
        ->assertRedirect();

    $this->actingAs($user)
        ->get(route('password.request'))
        ->assertRedirect();
});

it('regenerates session ID after successful login', function () {
    $user = User::factory()->create([
        'password' => 'password1234',
    ]);

    $this->get(route('login'));
    $oldSessionId = session()->getId();

    Volt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'password1234')
        ->call('login');

    expect(session()->getId())->not->toBe($oldSessionId);
});

it('invalidates session after logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user);
    $oldSessionId = session()->getId();

    $this->withoutMiddleware(VerifyCsrfToken::class)
        ->post(route('logout'));

    $this->assertGuest();
    expect(session()->getId())->not->toBe($oldSessionId);
});

it('rate limits login after 5 failed attempts', function () {
    User::factory()->create([
        'email' => 'test@example.com',
        'password' => 'password1234',
    ]);

    for ($i = 0; $i < 5; $i++) {
        $this->get(route('login'));
    }

    $this->get(route('login'))
        ->assertStatus(429);
});

it('rate limits registration at 3 per minute', function () {
    for ($i = 0; $i < 3; $i++) {
        $this->get(route('register'));
    }

    $this->get(route('register'))
        ->assertStatus(429);
});

it('rate limits forgot password at 3 per minute', function () {
    for ($i = 0; $i < 3; $i++) {
        $this->get(route('password.request'));
    }

    $this->get(route('password.request'))
        ->assertStatus(429);
});

it('does not reveal whether an email is already taken during registration', function () {
    User::factory()->create(['email' => 'existing@example.com']);

    $component = Volt::test('auth.register')
        ->set('name', 'Test User')
        ->set('email', 'existing@example.com')
        ->set('password', 'securepassword123')
        ->set('password_confirmation', 'securepassword123')
        ->call('register');

    // Should show success message, not an error
    $component->assertSet('registered', true);
    $component->assertHasNoErrors();
});

it('does not reveal whether an email exists for forgot password', function () {
    Volt::test('auth.forgot-password')
        ->set('email', 'nonexistent@example.com')
        ->call('sendResetLink')
        ->assertHasNoErrors()
        ->assertSet('status', "If an account with that email exists, we've sent a password reset link.");
});
