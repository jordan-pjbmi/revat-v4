<?php

use App\Services\WaitlistService;
use Livewire\Volt\Component;

new class extends Component {
    public bool $verified = false;
    public bool $invalid = false;

    public function mount(): void
    {
        $token = request()->query('token');

        if (! $token) {
            $this->invalid = true;
            return;
        }

        $service = app(WaitlistService::class);
        $result = $service->verify($token);

        if ($result) {
            $this->verified = true;
        } else {
            $this->invalid = true;
        }
    }
}; ?>

<x-layouts.auth-split>
    @volt('waitlist.verify')
        <div class="space-y-6">
            @if ($verified)
                <flux:callout variant="success" icon="check-circle">
                    <flux:callout.heading>You're confirmed!</flux:callout.heading>
                    <flux:callout.text>We'll let you know when it's your turn to get access. Thanks for your patience.</flux:callout.text>
                </flux:callout>
            @else
                <flux:callout variant="danger" icon="x-circle">
                    <flux:callout.heading>Invalid link</flux:callout.heading>
                    <flux:callout.text>This verification link is no longer valid.</flux:callout.text>
                </flux:callout>
            @endif

            <div>
                <a href="/" class="text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                    &larr; Back to home
                </a>
            </div>
        </div>
    @endvolt
</x-layouts.auth-split>
