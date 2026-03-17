<?php

use App\Jobs\Extraction\ExtractIntegration;
use App\Models\Integration;
use App\Services\Integrations\ConnectorRegistry;
use App\Services\PlanEnforcement\IntegrationLimitService;
use App\Services\WorkspaceContext;
use Livewire\Volt\Component;

new class extends Component
{
    public int $step = 1;
    public int $maxStepReached = 1;
    public string $platform = '';
    public string $name = '';
    public array $credentials = [];
    public array $selectedDataTypes = [];
    public int $syncInterval = 60;
    public ?array $connectionTestResult = null;
    public bool $testing = false;

    public function mount(): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            return;
        }

        // Check plan limit
        $limitService = app(IntegrationLimitService::class);
        if (! $limitService->canAdd($workspace->organization, $workspace)) {
            session()->flash('error', 'You have reached your integration limit. Upgrade your plan to add more.');
            $this->redirect(route('integrations'));
        }
    }

    public function selectPlatform(string $slug): void
    {
        $platformConfig = config("integrations.platforms.{$slug}");
        if (! $platformConfig) {
            return;
        }

        $this->platform = $slug;
        $this->credentials = [];
        $this->selectedDataTypes = $platformConfig['data_types'] ?? [];
        $this->connectionTestResult = null;
        $this->step = 2;
        $this->maxStepReached = 2;
    }

    public function getCredentialFieldsProperty(): array
    {
        if (! $this->platform) {
            return [];
        }

        return config("integrations.platforms.{$this->platform}.credential_fields", []);
    }

    public function goToStep(int $step): void
    {
        if ($step < 1 || $step > 4) {
            return;
        }

        // Can only go back to completed steps, not skip ahead
        if ($step <= $this->maxStepReached) {
            $this->step = $step;
        }
    }

    public function continueFromCredentials(): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
        ];

        foreach ($this->credentialFields as $field) {
            $rules["credentials.{$field['key']}"] = ['required', 'string'];
        }

        $this->validate($rules);

        $this->step = 3;
        $this->maxStepReached = max($this->maxStepReached, 3);
    }

    public function testConnection(): void
    {
        $this->connectionTestResult = null;

        $tempIntegration = new Integration([
            'platform' => $this->platform,
        ]);
        $tempIntegration->credentials = $this->credentials;

        try {
            $connector = app(ConnectorRegistry::class)->resolve($tempIntegration);
            $result = $connector->testConnection();
            $this->connectionTestResult = [
                'success' => $result->success,
                'message' => $result->message,
                'accountName' => $result->accountName,
            ];
        } catch (\Throwable $e) {
            $this->connectionTestResult = [
                'success' => false,
                'message' => "Connection test error: {$e->getMessage()}",
                'accountName' => null,
            ];
        }
    }

    public function continueFromTest(): void
    {
        if (! $this->connectionTestResult || ! $this->connectionTestResult['success']) {
            return;
        }

        $this->step = 4;
        $this->maxStepReached = 4;
    }

    public function saveIntegration(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:' . implode(',', array_keys(config('integrations.platforms', [])))],
            'selectedDataTypes' => ['required', 'array', 'min:1'],
            'syncInterval' => ['required', 'integer', 'in:' . implode(',', array_keys(config('integrations.sync_frequency_options', [])))],
        ]);

        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            $this->addError('name', 'No workspace selected.');
            return;
        }

        $exists = Integration::where('workspace_id', $workspace->id)
            ->where('platform', $this->platform)
            ->where('name', $this->name)
            ->exists();

        if ($exists) {
            $this->addError('name', "An integration named \"{$this->name}\" already exists for this platform.");
            $this->step = 2;
            return;
        }

        $integration = new Integration([
            'name' => $this->name,
            'platform' => $this->platform,
            'data_types' => $this->selectedDataTypes,
            'is_active' => true,
            'sync_interval_minutes' => $this->syncInterval,
        ]);
        $integration->workspace_id = $workspace->id;
        $integration->organization_id = $workspace->organization_id;
        $integration->save();

        $integration->setCredentials($this->credentials);

        $integration->markSyncStarted();
        ExtractIntegration::dispatch($integration);

        $this->redirect(route('integrations'));
    }

    public function with(): array
    {
        $platforms = config('integrations.platforms', []);
        $syncFrequencyOptions = config('integrations.sync_frequency_options', []);
        $dataTypeLabels = config('integrations.data_type_labels', []);

        $availableDataTypes = [];
        if ($this->platform) {
            $availableDataTypes = config("integrations.platforms.{$this->platform}.data_types", []);
        }

        return [
            'platforms' => $platforms,
            'syncFrequencyOptions' => $syncFrequencyOptions,
            'dataTypeLabels' => $dataTypeLabels,
            'availableDataTypes' => $availableDataTypes,
        ];
    }
}; ?>

<x-layouts.app>
    <x-slot:title>Add Integration</x-slot:title>

    @volt('integrations.create')
    <div class="max-w-4xl">
        {{-- Header --}}
        <div class="flex items-center gap-3 mb-6">
            <a href="{{ route('integrations') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                Back
            </a>
            <h1 class="text-[22px] font-bold text-slate-900 dark:text-white">Add Integration</h1>
        </div>

        {{-- Stepper --}}
        <nav class="flex items-center gap-1 mb-8">
            @php
                $steps = [
                    1 => 'Platform',
                    2 => 'Credentials',
                    3 => 'Test',
                    4 => 'Settings',
                ];
            @endphp

            @foreach ($steps as $num => $label)
                @if ($num > 1)
                    <svg class="w-4 h-4 text-slate-300 dark:text-slate-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                @endif

                <button
                    wire:click="goToStep({{ $num }})"
                    @class([
                        'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium transition-colors',
                        'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $step === $num,
                        'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-700 dark:text-slate-200 dark:hover:bg-slate-600 cursor-pointer' => $step !== $num && $num <= $maxStepReached,
                        'text-slate-400 dark:text-slate-500 cursor-default' => $num > $maxStepReached,
                    ])
                    @if($num > $maxStepReached) disabled @endif
                >
                    <span class="text-xs">{{ $num }}</span>
                    {{ $label }}
                </button>
            @endforeach
        </nav>

        {{-- Step 1: Platform Selection --}}
        @if ($step === 1)
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach ($platforms as $slug => $config)
                    <button
                        wire:click="selectPlatform('{{ $slug }}')"
                        class="flex items-center gap-4 p-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 hover:border-slate-300 dark:hover:border-slate-600 hover:shadow-sm transition-all text-left group"
                    >
                        <span class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-slate-100 dark:bg-slate-700 text-sm font-bold text-slate-700 dark:text-slate-200 shrink-0">
                            {{ $config['short'] }}
                        </span>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-semibold text-slate-900 dark:text-white">{{ $config['label'] }}</div>
                            <div class="text-xs text-slate-500 dark:text-slate-400">{{ $config['description'] }}</div>
                        </div>
                        <svg class="w-5 h-5 text-slate-300 dark:text-slate-600 group-hover:text-slate-400 dark:group-hover:text-slate-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </button>
                @endforeach
            </div>
        @endif

        {{-- Step 2: Credentials --}}
        @if ($step === 2)
            <div class="max-w-md space-y-5">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Integration Name</label>
                    <flux:input wire:model="name" type="text" placeholder="e.g. My Mailchimp Account" />
                    @error('name') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                @foreach ($this->credentialFields as $field)
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $field['label'] }}</label>
                        <flux:input
                            wire:model="credentials.{{ $field['key'] }}"
                            type="{{ $field['type'] === 'password' || str_contains($field['key'], 'key') || str_contains($field['key'], 'secret') || str_contains($field['key'], 'token') ? 'password' : 'text' }}"
                            placeholder="{{ $field['placeholder'] }}"
                        />
                        @error("credentials.{$field['key']}") <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                @endforeach

                <div class="flex items-center gap-3 pt-2">
                    <flux:button variant="ghost" wire:click="goToStep(1)">Back</flux:button>
                    <flux:button variant="primary" wire:click="continueFromCredentials">Continue</flux:button>
                </div>
            </div>
        @endif

        {{-- Step 3: Test Connection --}}
        @if ($step === 3)
            <div class="max-w-md space-y-5">
                <p class="text-sm text-slate-600 dark:text-slate-300">Test your connection to verify the credentials are correct.</p>

                @if ($connectionTestResult)
                    @if ($connectionTestResult['success'])
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50 dark:bg-emerald-900/20 dark:border-emerald-800 p-4">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                <span class="text-sm font-semibold text-emerald-700 dark:text-emerald-400">Connection successful</span>
                            </div>
                            @if ($connectionTestResult['accountName'])
                                <p class="text-sm text-emerald-600 dark:text-emerald-400 mt-1">Account: {{ $connectionTestResult['accountName'] }}</p>
                            @endif
                        </div>
                    @else
                        <div class="rounded-xl border border-red-200 bg-red-50 dark:bg-red-900/20 dark:border-red-800 p-4">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                <span class="text-sm font-semibold text-red-700 dark:text-red-400">Connection failed</span>
                            </div>
                            <p class="text-sm text-red-600 dark:text-red-400 mt-1">{{ $connectionTestResult['message'] }}</p>
                        </div>
                    @endif
                @endif

                <div>
                    @if (! $connectionTestResult)
                        <flux:button variant="primary" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                            <span wire:loading.remove wire:target="testConnection" class="inline-flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" /></svg>
                                Test Connection
                            </span>
                            <span wire:loading wire:target="testConnection">Testing...</span>
                        </flux:button>
                    @elseif ($connectionTestResult['success'])
                        <div class="flex items-center gap-3">
                            <flux:button variant="ghost" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                                <span wire:loading.remove wire:target="testConnection">Retry</span>
                                <span wire:loading wire:target="testConnection">Testing...</span>
                            </flux:button>
                            <flux:button variant="primary" wire:click="continueFromTest">Continue</flux:button>
                        </div>
                    @else
                        <div class="flex items-center gap-3">
                            <flux:button variant="ghost" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                                <span wire:loading.remove wire:target="testConnection">Retry</span>
                                <span wire:loading wire:target="testConnection">Testing...</span>
                            </flux:button>
                            <flux:button variant="ghost" wire:click="goToStep(2)">Back to Credentials</flux:button>
                        </div>
                    @endif
                </div>

                <div>
                    <a wire:click="goToStep(2)" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 cursor-pointer">Back to Credentials</a>
                </div>
            </div>
        @endif

        {{-- Step 4: Settings --}}
        @if ($step === 4)
            <div class="max-w-md space-y-6">
                {{-- Data Types --}}
                <div>
                    <h3 class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-3">Data Types</h3>
                    <div class="space-y-2">
                        @foreach ($availableDataTypes as $dataType)
                            <flux:checkbox
                                wire:model="selectedDataTypes"
                                value="{{ $dataType }}"
                                label="{{ $dataTypeLabels[$dataType] ?? ucwords(str_replace('_', ' ', $dataType)) }}"
                            />
                        @endforeach
                    </div>
                </div>

                {{-- Sync Frequency --}}
                <div>
                    <h3 class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-3">Sync Frequency</h3>
                    <flux:select wire:model="syncInterval">
                        @foreach ($syncFrequencyOptions as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                @error('selectedDataTypes') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                <div class="flex items-center gap-3 pt-2">
                    <flux:button variant="ghost" wire:click="goToStep(3)">Back</flux:button>
                    <flux:button variant="primary" wire:click="saveIntegration" icon="check">
                        Save & Connect
                    </flux:button>
                </div>
            </div>
        @endif
    </div>
    @endvolt
</x-layouts.app>
