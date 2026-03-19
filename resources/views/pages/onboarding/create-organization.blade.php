<?php

use App\Models\AlphaInvite;
use App\Models\Plan;
use App\Services\OrganizationSetupService;
use Livewire\Volt\Component;

new class extends Component
{
    public int $step = 1;

    public string $companyName = '';

    public string $timezone = 'UTC';

    public string $planSlug = '';

    public string $workspaceName = '';

    public bool $hasPlans = false;

    public bool $isAlphaUser = false;

    public function mount(): void
    {
        $this->isAlphaUser = AlphaInvite::where('email', auth()->user()->email)
            ->whereNotNull('registered_at')
            ->exists();

        $plans = Plan::visible()->get();
        $this->hasPlans = ! $this->isAlphaUser && $plans->isNotEmpty();

        if ($this->hasPlans) {
            $this->planSlug = $plans->first()->slug;
        }
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validate([
                'companyName' => ['required', 'string', 'max:255', 'unique:organizations,name'],
                'timezone' => ['required', 'string', 'timezone:all'],
            ]);

            // Auto-fill workspace name if empty
            if (empty($this->workspaceName)) {
                $this->workspaceName = $this->companyName;
            }

            // Skip plan step if no visible plans
            $this->step = $this->hasPlans ? 2 : 3;
        } elseif ($this->step === 2) {
            $this->validate([
                'planSlug' => ['required', 'exists:plans,slug'],
            ]);

            $this->step = 3;
        }
    }

    public function previousStep(): void
    {
        if ($this->step === 3) {
            $this->step = $this->hasPlans ? 2 : 1;
        } elseif ($this->step === 2) {
            $this->step = 1;
        }
    }

    public function submit(OrganizationSetupService $service): void
    {
        $this->validate([
            'workspaceName' => ['required', 'string', 'max:255'],
        ]);

        $data = [
            'name' => $this->companyName,
            'timezone' => $this->timezone,
            'workspace_name' => $this->workspaceName,
        ];

        if ($this->hasPlans && ! empty($this->planSlug)) {
            $data['plan_slug'] = $this->planSlug;
        }

        $service->setup(auth()->user(), $data);

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }

    public function getTimezonesProperty(): array
    {
        $timezones = timezone_identifiers_list();
        $result = ['UTC'];

        foreach ($timezones as $tz) {
            if ($tz === 'UTC') {
                continue;
            }
            $parts = explode('/', $tz, 2);
            $region = $parts[0];
            if (in_array($region, ['Africa', 'America', 'Antarctica', 'Arctic', 'Asia', 'Atlantic', 'Australia', 'Europe', 'Indian', 'Pacific'])) {
                $result[] = $tz;
            }
        }

        return $result;
    }

    public function getPlansProperty()
    {
        return Plan::visible()->get();
    }
}; ?>

<x-layouts.onboarding>
    <x-slot:title>Set Up Your Account</x-slot:title>

    @volt('onboarding.wizard')
    <div>
        {{-- Step indicator --}}
        <div class="flex justify-center gap-2 mb-6">
            @for ($i = 1; $i <= 3; $i++)
                <div class="w-2.5 h-2.5 rounded-full {{ $i <= $step ? 'bg-zinc-900 dark:bg-white' : 'bg-zinc-300 dark:bg-zinc-600' }}"></div>
            @endfor
        </div>

        {{-- Step 1: Create your company --}}
        @if ($step === 1)
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Create your company</h1>
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    Tell us about your business to get started.
                </p>
            </div>

            <form wire:submit="nextStep" class="space-y-6">
                <flux:input
                    wire:model="companyName"
                    label="Company name"
                    type="text"
                    placeholder="Acme Inc."
                    required
                    autofocus
                />

                <flux:select wire:model="timezone" label="Timezone" searchable>
                    @foreach ($this->timezones as $tz)
                        <flux:select.option value="{{ $tz }}">{{ str_replace('_', ' ', $tz) }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:button type="submit" variant="primary" class="w-full">
                    Continue
                </flux:button>
            </form>
        @endif

        {{-- Step 2: Choose your plan --}}
        @if ($step === 2)
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Choose your plan</h1>
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    You can change this anytime. All plans include a 14-day free trial.
                </p>
            </div>

            <div class="space-y-3 mb-6">
                @foreach ($this->plans as $plan)
                    <label
                        class="flex items-center justify-between p-4 border rounded-lg cursor-pointer transition-colors
                            {{ $planSlug === $plan->slug
                                ? 'border-zinc-900 dark:border-white ring-1 ring-zinc-900 dark:ring-white'
                                : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-400 dark:hover:border-zinc-500' }}"
                        wire:key="plan-{{ $plan->slug }}"
                    >
                        <input type="radio" wire:model.live="planSlug" value="{{ $plan->slug }}" class="sr-only">
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $plan->name }}</span>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $plan->max_users }} users &middot; {{ $plan->max_workspaces }} workspaces</span>
                    </label>
                @endforeach
            </div>

            <div class="flex items-center gap-3">
                <flux:button wire:click="previousStep" variant="ghost" type="button">
                    Back
                </flux:button>

                <flux:button wire:click="nextStep" variant="primary" class="w-full" type="button">
                    Continue
                </flux:button>
            </div>
        @endif

        {{-- Step 3: Name your workspace --}}
        @if ($step === 3)
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Name your workspace</h1>
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    Workspaces help you organize your projects and clients.
                </p>
            </div>

            <form wire:submit="submit" class="space-y-6">
                <flux:input
                    wire:model="workspaceName"
                    label="Workspace name"
                    type="text"
                    placeholder="My Workspace"
                    required
                    autofocus
                />

                <div class="flex items-center gap-3">
                    <flux:button wire:click="previousStep" variant="ghost" type="button">
                        Back
                    </flux:button>

                    <flux:button type="submit" variant="primary" class="w-full">
                        Create company
                    </flux:button>
                </div>
            </form>
        @endif
    </div>
    @endvolt
</x-layouts.onboarding>
