<?php

use App\Jobs\Extraction\ExtractIntegration;
use App\Models\Integration;
use App\Services\Integrations\ConnectorRegistry;
use App\Services\WorkspaceContext;
use Livewire\Volt\Component;

new class extends Component
{
    public bool $showCreateModal = false;

    public string $name = '';

    public string $platform = '';

    public array $credentials = [];

    public array $selectedDataTypes = [];

    public int $syncInterval = 60;

    public ?array $connectionTestResult = null;

    public function openCreateModal(): void
    {
        $this->reset(['name', 'platform', 'credentials', 'selectedDataTypes', 'syncInterval', 'connectionTestResult']);
        $this->syncInterval = 60;
        $this->showCreateModal = true;
    }

    public function updatedPlatform(): void
    {
        $this->credentials = [];
        $this->selectedDataTypes = [];
        $this->connectionTestResult = null;

        $platformConfig = config("integrations.platforms.{$this->platform}");
        if ($platformConfig) {
            $this->selectedDataTypes = $platformConfig['data_types'] ?? [];
        }
    }

    public function getCredentialFieldsProperty(): array
    {
        if (! $this->platform) {
            return [];
        }

        $platformConfig = config("integrations.platforms.{$this->platform}");

        return $platformConfig['credential_fields'] ?? [];
    }

    public function getAvailableDataTypesProperty(): array
    {
        if (! $this->platform) {
            return [];
        }

        $platformConfig = config("integrations.platforms.{$this->platform}");

        return $platformConfig['data_types'] ?? [];
    }

    public function testConnection(): void
    {
        $this->connectionTestResult = null;

        if (! $this->platform) {
            $this->connectionTestResult = ['success' => false, 'message' => 'Please select a platform first.'];

            return;
        }

        // Validate credential fields are filled
        foreach ($this->credentialFields as $field) {
            if (empty($this->credentials[$field] ?? '')) {
                $this->connectionTestResult = ['success' => false, 'message' => 'Please fill in all credential fields.'];

                return;
            }
        }

        // Build a temporary (non-persisted) integration to resolve the connector
        $tempIntegration = new Integration([
            'platform' => $this->platform,
        ]);
        $tempIntegration->credentials = $this->credentials;

        try {
            $connector = app(ConnectorRegistry::class)->resolve($tempIntegration);
            $result = $connector->testConnection();
            $this->connectionTestResult = ['success' => $result->success, 'message' => $result->message];
        } catch (\Throwable $e) {
            $this->connectionTestResult = ['success' => false, 'message' => "Connection test error: {$e->getMessage()}"];
        }
    }

    public function createIntegration(): void
    {
        $credentialRules = [];
        foreach ($this->credentialFields as $field) {
            $credentialRules["credentials.{$field}"] = ['required', 'string'];
        }

        $this->validate(array_merge([
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:'.implode(',', array_keys(config('integrations.platforms', [])))],
            'selectedDataTypes' => ['required', 'array', 'min:1'],
            'syncInterval' => ['required', 'integer', 'min:1', 'max:1440'],
        ], $credentialRules));

        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            $this->addError('name', 'No workspace selected.');

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

        $this->showCreateModal = false;
        $this->reset(['name', 'platform', 'credentials', 'selectedDataTypes', 'syncInterval', 'connectionTestResult']);
    }

    public function syncNow(int $integrationId): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            return;
        }

        $integration = Integration::forWorkspace($workspace->id)->findOrFail($integrationId);

        if ($integration->sync_in_progress && ! $integration->isSyncStale()) {
            return;
        }

        $integration->markSyncStarted();
        ExtractIntegration::dispatch($integration);
    }

    public function deleteIntegration(int $integrationId): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            return;
        }

        $integration = Integration::forWorkspace($workspace->id)->findOrFail($integrationId);

        $integration->delete();
    }

    public function with(): array
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            return ['integrations' => collect(), 'platforms' => [], 'hasSyncInProgress' => false];
        }

        $integrations = Integration::forWorkspace($workspace->id)
            ->orderBy('name')
            ->get();

        $platforms = config('integrations.platforms', []);

        $hasSyncInProgress = $integrations->contains(fn ($i) => $i->sync_in_progress);

        return [
            'integrations' => $integrations,
            'platforms' => $platforms,
            'hasSyncInProgress' => $hasSyncInProgress,
        ];
    }
}; ?>

<x-layouts.app>
    <x-slot:title>Integrations</x-slot:title>

    @volt('integrations.index')
    <div @if ($hasSyncInProgress) wire:poll.5s @endif>
        <div class="flex justify-between items-start mb-6">
            <div>
                <h1 class="text-[22px] font-bold text-slate-900 dark:text-white">Integrations</h1>
                <p class="text-[13px] text-slate-600 dark:text-slate-300 mt-0.5">Manage your data source connections</p>
            </div>
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                Add Integration
            </flux:button>
        </div>

        {{-- Integrations Table --}}
        <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="overflow-x-auto" wire:loading.class="opacity-50">
                @if ($integrations->count() > 0)
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-700">
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Name</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Platform</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Status</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Sync Status</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Last Synced</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Data Types</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-[12.5px]">
                            @foreach ($integrations as $integration)
                                <tr class="border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-700/30">
                                    <td class="px-3 py-2.5 font-medium text-slate-800 dark:text-slate-200">{{ $integration->name }}</td>
                                    <td class="px-3 py-2.5 text-slate-600 dark:text-slate-300">{{ $platforms[$integration->platform]['label'] ?? ucfirst($integration->platform) }}</td>
                                    <td class="px-3 py-2.5">
                                        @if ($integration->is_active)
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                Active
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-400">
                                                <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                                                Inactive
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5">
                                        @if ($integration->sync_in_progress)
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                                                <svg class="w-3 h-3 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                                @switch($integration->last_sync_status)
                                                    @case('extracting')
                                                        Extracting data...
                                                        @break
                                                    @case('transforming')
                                                        Processing data...
                                                        @break
                                                    @case('attributing')
                                                        Running attribution...
                                                        @break
                                                    @default
                                                        Syncing...
                                                @endswitch
                                            </span>
                                        @elseif ($integration->last_sync_status === 'completed')
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">
                                                Completed
                                            </span>
                                        @elseif ($integration->last_sync_status === 'failed')
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400" title="{{ $integration->last_sync_error }}">
                                                Failed
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-400">
                                                Never synced
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 font-mono text-slate-500">{{ $integration->last_synced_at?->diffForHumans() ?? '-' }}</td>
                                    <td class="px-3 py-2.5 text-slate-600 dark:text-slate-300">
                                        @foreach ($integration->data_types ?? [] as $dataType)
                                            <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300 mr-1">{{ str_replace('_', ' ', $dataType) }}</span>
                                        @endforeach
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <div class="flex items-center gap-1">
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="arrow-path"
                                                wire:click="syncNow({{ $integration->id }})"
                                                :disabled="$integration->sync_in_progress && ! $integration->isSyncStale()"
                                                title="Sync Now"
                                            >
                                                {{ $integration->isSyncStale() ? 'Retry Sync' : 'Sync Now' }}
                                            </flux:button>
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="trash"
                                                wire:click="deleteIntegration({{ $integration->id }})"
                                                wire:confirm="Are you sure you want to delete this integration? This cannot be undone."
                                                title="Delete"
                                                class="text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="text-center py-16">
                        <flux:icon name="puzzle-piece" class="w-10 h-10 text-slate-300 dark:text-slate-600 mx-auto mb-3" />
                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No integrations configured</p>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Connect a data source to start syncing your marketing data</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Create Integration Modal --}}
        <flux:modal wire:model.self="showCreateModal" class="max-w-lg">
            <div class="space-y-6">
                <flux:heading>Add Integration</flux:heading>

                <form wire:submit="createIntegration" class="space-y-4">
                    <flux:select wire:model.live="platform" label="Platform" placeholder="Select a platform...">
                        @foreach ($platforms as $key => $config)
                            <flux:select.option value="{{ $key }}">{{ $config['label'] }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="name" label="Integration Name" type="text" placeholder="e.g. My ActiveCampaign" required />

                    @if ($this->credentialFields)
                        <div class="space-y-3">
                            <p class="text-sm font-medium text-slate-700 dark:text-slate-300">Credentials</p>
                            @foreach ($this->credentialFields as $field)
                                <flux:input
                                    wire:model="credentials.{{ $field }}"
                                    label="{{ str_replace('_', ' ', ucwords($field, '_')) }}"
                                    type="{{ str_contains($field, 'key') || str_contains($field, 'secret') || str_contains($field, 'token') ? 'password' : 'text' }}"
                                    placeholder="Enter {{ str_replace('_', ' ', $field) }}"
                                    required
                                />
                            @endforeach
                        </div>
                    @endif

                    @if ($this->availableDataTypes)
                        <div class="space-y-2">
                            <p class="text-sm font-medium text-slate-700 dark:text-slate-300">Data Types</p>
                            @foreach ($this->availableDataTypes as $dataType)
                                <flux:checkbox
                                    wire:model="selectedDataTypes"
                                    value="{{ $dataType }}"
                                    label="{{ ucwords(str_replace('_', ' ', $dataType)) }}"
                                />
                            @endforeach
                        </div>
                    @endif

                    <flux:input
                        wire:model="syncInterval"
                        label="Sync Interval (minutes)"
                        type="number"
                        min="1"
                        max="1440"
                    />

                    @if ($this->credentialFields)
                        <div class="flex items-center gap-3">
                            <flux:button type="button" variant="ghost" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                                <span wire:loading.remove wire:target="testConnection">Test Connection</span>
                                <span wire:loading wire:target="testConnection">Testing...</span>
                            </flux:button>

                            @if ($connectionTestResult)
                                <p class="text-sm {{ $connectionTestResult['success'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                                    {{ $connectionTestResult['message'] }}
                                </p>
                            @endif
                        </div>
                    @endif

                    <div class="flex justify-end gap-2 pt-2">
                        <flux:button wire:click="$set('showCreateModal', false)" variant="ghost">Cancel</flux:button>
                        <flux:button type="submit" variant="primary" :disabled="$this->credentialFields && (! $connectionTestResult || ! $connectionTestResult['success'])">Create Integration</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    </div>
    @endvolt
</x-layouts.app>
