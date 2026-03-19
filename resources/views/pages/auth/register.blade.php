<?php

use App\Models\User;
use App\Services\AlphaInviteService;
use App\Services\AlphaAgreementService;
use App\Services\WaitlistService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    // Registration fields
    public string $name = '';
    #[Locked]
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public bool $agree_to_terms = false;
    #[Locked]
    public string $token = '';

    // Waitlist fields
    public string $waitlist_email = '';

    // State
    #[Locked]
    public bool $showRegistration = false;
    #[Locked]
    public bool $showWaitlist = false;
    #[Locked]
    public bool $tokenError = false;
    #[Locked]
    public bool $waitlistSubmitted = false;

    public function mount(string $token = ''): void
    {
        $this->token = $token ?: request()->query('token', '');

        if ($this->token) {
            $service = app(AlphaInviteService::class);
            $invite = $service->findByToken($this->token);

            if ($invite) {
                $this->showRegistration = true;
                $this->email = $invite->email;
            } else {
                $this->tokenError = true;
                $this->showWaitlist = true;
            }
        } else {
            $this->showWaitlist = true;
        }
    }

    public function register(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:8|confirmed',
            'agree_to_terms' => 'accepted',
        ]);

        // Re-validate token
        $service = app(AlphaInviteService::class);
        $invite = $service->findByToken($this->token);

        if (! $invite) {
            $this->tokenError = true;
            $this->showRegistration = false;
            $this->showWaitlist = true;
            return;
        }

        // Record agreement BEFORE user creation (audit trail)
        // Use try/catch to handle double-submit gracefully
        try {
            app(AlphaAgreementService::class)->record(
                email: $invite->email,
                ipAddress: request()->ip(),
                userAgent: request()->userAgent(),
            );
        } catch (\InvalidArgumentException) {
            // Agreement already recorded (double-submit) — continue
        }

        // Check if user already exists
        if (User::where('email', $invite->email)->exists()) {
            $service->markRegistered($invite);
            session()->flash('status', 'You already have an account. Please log in.');
            $this->redirect(route('login'), navigate: false);
            return;
        }

        // Create user
        $user = User::create([
            'name' => $this->name,
            'email' => $invite->email,
            'password' => $this->password,
        ]);

        $service->markRegistered($invite);

        event(new Registered($user));

        Auth::login($user);

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }

    public function joinWaitlist(): void
    {
        $key = 'waitlist-join:' . request()->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->waitlistSubmitted = true;
            return;
        }
        RateLimiter::hit($key, 60);

        $this->validate([
            'waitlist_email' => 'required|email|max:254',
        ]);

        app(WaitlistService::class)->join($this->waitlist_email);

        $this->waitlistSubmitted = true;
    }
}; ?>

<x-layouts.auth-split>
    <x-slot:title>Register</x-slot:title>

    @volt('auth.register')
        <div class="space-y-6">
            @if ($tokenError)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.heading>Invalid invite link</flux:callout.heading>
                    <flux:callout.text>This invite link is no longer valid. You can join the waitlist below.</flux:callout.text>
                </flux:callout>
            @endif

            @if ($showRegistration)
                {{-- Alpha invite registration form --}}
                    <div>
                        <h1 class="text-xl font-semibold">Create your account</h1>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">You've been invited to join the Revat alpha.</p>
                    </div>

                    <form wire:submit="register" class="space-y-4">
                        <flux:input wire:model="name" label="Name" type="text" required autofocus />
                        <flux:input wire:model="email" label="Email" type="email" readonly />
                        <flux:input wire:model="password" label="Password" type="password" required />
                        <flux:input wire:model="password_confirmation" label="Confirm password" type="password" required />

                        <label class="flex items-start gap-2">
                            <input type="checkbox" wire:model="agree_to_terms" class="mt-1 rounded border-zinc-300 text-amber-600 focus:ring-amber-500 dark:border-zinc-600 dark:bg-zinc-800" />
                            <span class="text-sm text-zinc-600 dark:text-zinc-400">
                                I agree to the <a href="{{ route('alpha.agreement') }}" target="_blank" class="underline hover:text-zinc-900 dark:hover:text-zinc-200">Alpha Testing Agreement</a>
                            </span>
                        </label>
                        @error('agree_to_terms')
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @enderror

                        <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
                            Create account
                        </flux:button>
                    </form>

                    <p class="text-sm text-center text-zinc-500 dark:text-zinc-400">
                        Already have an account? <a href="{{ route('login') }}" class="underline hover:text-zinc-900 dark:hover:text-zinc-200" wire:navigate>Log in</a>
                    </p>
            @endif

            @if ($showWaitlist)
                {{-- Waitlist signup form --}}
                @if ($waitlistSubmitted)
                    <flux:callout variant="success" icon="check-circle">
                        <flux:callout.heading>Check your email</flux:callout.heading>
                        <flux:callout.text>We've sent a confirmation link to verify your spot on the waitlist.</flux:callout.text>
                    </flux:callout>
                @else
                    <div>
                        <h1 class="text-xl font-semibold">Join the waitlist</h1>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Revat is currently in private alpha. Enter your email to be notified when we open up access.</p>
                    </div>

                    <form wire:submit="joinWaitlist" class="space-y-4">
                        <flux:input wire:model="waitlist_email" label="Email" type="email" required autofocus placeholder="you@company.com" />

                        <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
                            Join the Waitlist
                        </flux:button>
                    </form>

                    <p class="text-sm text-center text-zinc-500 dark:text-zinc-400">
                        Already have an account? <a href="{{ route('login') }}" class="underline hover:text-zinc-900 dark:hover:text-zinc-200" wire:navigate>Log in</a>
                    </p>
                @endif
            @endif
        </div>
    @endvolt
</x-layouts.auth-split>
