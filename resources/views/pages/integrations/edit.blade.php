<?php

use App\Models\Integration;
use App\Services\Integrations\ConnectorRegistry;
use App\Services\WorkspaceContext;
use Livewire\Volt\Component;

new class extends Component
{
    public Integration $integration;
    public string $name = '';
    public array $credentials = [];
    public array $selectedDataTypes = [];
    public int $syncInterval = 60;
    public ?array $connectionTestResult = null;

    public function mount(): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            $this->redirect(route('integrations'));
            return;
        }

        $integrationId = request()->route('integration');
        $this->integration = Integration::forWorkspace($workspace->id)->findOrFail($integrationId);

        $this->name = $this->integration->name;
        $this->selectedDataTypes = $this->integration->data_types ?? [];
        $this->syncInterval = $this->integration->sync_interval_minutes ?? 60;

        // Pre-fill credentials with empty strings (password fields show dots in the UI)
        $credentialFields = config("integrations.platforms.{$this->integration->platform}.credential_fields", []);
        $existingCredentials = $this->integration->credentials ?? [];
        foreach ($credentialFields as $field) {
            $key = is_array($field) ? $field['key'] : $field;
            $this->credentials[$key] = $existingCredentials[$key] ?? '';
        }
    }

    public function getCredentialFieldsProperty(): array
    {
        return config("integrations.platforms.{$this->integration->platform}.credential_fields", []);
    }

    public function testConnection(): void
    {
        $this->connectionTestResult = null;

        $tempIntegration = new Integration([
            'platform' => $this->integration->platform,
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

    public function saveIntegration(): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'selectedDataTypes' => ['required', 'array', 'min:1'],
            'syncInterval' => ['required', 'integer', 'in:' . implode(',', array_keys(config('integrations.sync_frequency_options', [])))],
        ];

        foreach ($this->credentialFields as $field) {
            $key = is_array($field) ? $field['key'] : $field;
            $rules["credentials.{$key}"] = ['required', 'string'];
        }

        $this->validate($rules);

        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            $this->addError('name', 'No workspace selected.');
            return;
        }

        // Check for duplicate name (excluding this integration)
        $exists = Integration::where('workspace_id', $workspace->id)
            ->where('platform', $this->integration->platform)
            ->where('name', $this->name)
            ->where('id', '!=', $this->integration->id)
            ->exists();

        if ($exists) {
            $this->addError('name', "An integration named \"{$this->name}\" already exists for this platform.");
            return;
        }

        $this->integration->name = $this->name;
        $this->integration->data_types = $this->selectedDataTypes;
        $this->integration->sync_interval_minutes = $this->syncInterval;
        $this->integration->save();

        $this->integration->setCredentials($this->credentials);

        session()->flash('success', 'Integration updated successfully.');
        $this->redirect(route('integrations'));
    }

    public function with(): array
    {
        $platformConfig = config("integrations.platforms.{$this->integration->platform}", []);
        $syncFrequencyOptions = config('integrations.sync_frequency_options', []);
        $dataTypeLabels = config('integrations.data_type_labels', []);
        $availableDataTypes = $platformConfig['data_types'] ?? [];

        return [
            'platformConfig' => $platformConfig,
            'syncFrequencyOptions' => $syncFrequencyOptions,
            'dataTypeLabels' => $dataTypeLabels,
            'availableDataTypes' => $availableDataTypes,
        ];
    }
}; ?>

<x-layouts.app>
    <x-slot:title>Edit Integration</x-slot:title>

    @volt('integrations.edit')
    <div class="max-w-2xl">
        {{-- Header --}}
        <div class="flex items-center gap-3 mb-6">
            <a href="{{ route('integrations') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                Back
            </a>
            <h1 class="text-[22px] font-bold text-slate-900 dark:text-white">Edit Integration</h1>
        </div>

        {{-- Platform badge --}}
        <div class="flex items-center gap-3 mb-6">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-slate-100 dark:bg-slate-700 text-sm font-bold text-slate-700 dark:text-slate-200">
                {{ $platformConfig['short'] ?? strtoupper(substr($integration->platform, 0, 2)) }}
            </span>
            <div>
                <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $platformConfig['label'] ?? ucfirst($integration->platform) }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $platformConfig['description'] ?? '' }}</p>
            </div>
        </div>

        <div class="space-y-6">
            {{-- Integration Name --}}
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Integration Name</label>
                <flux:input wire:model="name" type="text" placeholder="e.g. My ActiveCampaign" />
                @error('name') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- Credentials --}}
            <div class="space-y-4">
                <h3 class="text-sm font-medium text-slate-700 dark:text-slate-300">Credentials</h3>

                @foreach ($this->credentialFields as $field)
                    @php
                        $key = is_array($field) ? $field['key'] : $field;
                        $label = is_array($field) ? $field['label'] : ucwords(str_replace('_', ' ', $field));
                        $placeholder = is_array($field) ? $field['placeholder'] : "Enter {$label}";
                        $isPassword = (is_array($field) && ($field['type'] ?? 'text') === 'password')
                            || str_contains($key, 'key') || str_contains($key, 'secret') || str_contains($key, 'token');
                    @endphp
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">{{ $label }}</label>
                        <flux:input
                            wire:model="credentials.{{ $key }}"
                            type="{{ $isPassword ? 'password' : 'text' }}"
                            placeholder="{{ $placeholder }}"
                        />
                        @error("credentials.{$key}") <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>

            {{-- Test Connection --}}
            <div class="space-y-3">
                <flux:button variant="ghost" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                    <span wire:loading.remove wire:target="testConnection" class="inline-flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" /></svg>
                        Test Connection
                    </span>
                    <span wire:loading wire:target="testConnection">Testing...</span>
                </flux:button>

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
            </div>

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
                @error('selectedDataTypes') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
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

            {{-- Actions --}}
            <div class="flex items-center gap-3 pt-2">
                <a href="{{ route('integrations') }}">
                    <flux:button variant="ghost">Cancel</flux:button>
                </a>
                <flux:button variant="primary" wire:click="saveIntegration">Save</flux:button>
            </div>
        </div>
    </div>
    @endvolt
</x-layouts.app>
