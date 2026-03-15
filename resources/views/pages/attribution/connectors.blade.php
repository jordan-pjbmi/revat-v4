<?php

use App\Models\AttributionConnector;
use App\Models\Integration;
use App\Services\WorkspaceContext;
use Livewire\Volt\Component;

new class extends Component
{
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $type = 'mapped';

    public string $campaignIntegrationId = '';

    public string $campaignDataType = '';

    public string $conversionIntegrationId = '';

    public string $conversionDataType = '';

    public array $fieldMappings = [];

    public bool $isActive = true;

    // Simple connector fields
    public string $effortCodeField = '';

    public string $effortCodeSource = 'campaign';

    public function openCreateModal(): void
    {
        $this->reset([
            'editingId', 'name', 'type', 'campaignIntegrationId', 'campaignDataType',
            'conversionIntegrationId', 'conversionDataType', 'fieldMappings', 'isActive',
            'effortCodeField', 'effortCodeSource',
        ]);
        $this->type = 'mapped';
        $this->isActive = true;
        $this->effortCodeSource = 'campaign';
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();
        if (! $workspace) {
            return;
        }

        $connector = AttributionConnector::forWorkspace($workspace->id)->findOrFail($id);
        $this->editingId = $connector->id;
        $this->name = $connector->name;
        $this->type = $connector->type ?? 'mapped';
        $this->campaignIntegrationId = (string) ($connector->campaign_integration_id ?? '');
        $this->campaignDataType = $connector->campaign_data_type ?? '';
        $this->conversionIntegrationId = (string) ($connector->conversion_integration_id ?? '');
        $this->conversionDataType = $connector->conversion_data_type ?? '';
        $this->isActive = $connector->is_active;

        $mappings = $connector->field_mappings ?? [];

        if ($this->type === 'simple') {
            $this->effortCodeField = $mappings['effort_code_field'] ?? '';
            $this->effortCodeSource = $mappings['effort_code_source'] ?? 'campaign';
            $this->fieldMappings = [];
        } else {
            $this->effortCodeField = '';
            $this->effortCodeSource = 'campaign';
            // Convert indexed mappings to array for the form
            $this->fieldMappings = collect($mappings)
                ->filter(fn ($v, $k) => is_array($v) && isset($v['campaign'], $v['conversion']))
                ->values()
                ->toArray();
        }

        $this->showModal = true;
    }

    public function updatedType(): void
    {
        $this->fieldMappings = [];
        $this->effortCodeField = '';
        $this->effortCodeSource = 'campaign';
    }

    public function addMappingRow(): void
    {
        $this->fieldMappings[] = ['campaign' => '', 'conversion' => ''];
    }

    public function removeMappingRow(int $index): void
    {
        unset($this->fieldMappings[$index]);
        $this->fieldMappings = array_values($this->fieldMappings);
    }

    public function save(): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();
        if (! $workspace) {
            return;
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:mapped,simple'],
            'isActive' => ['boolean'],
        ];

        if ($this->type === 'mapped') {
            $rules['campaignIntegrationId'] = ['required', 'exists:integrations,id'];
            $rules['campaignDataType'] = ['required', 'string'];
            $rules['conversionIntegrationId'] = ['required', 'exists:integrations,id'];
            $rules['conversionDataType'] = ['required', 'string'];
            $rules['fieldMappings'] = ['required', 'array', 'min:1'];
            $rules['fieldMappings.*.campaign'] = ['required', 'string'];
            $rules['fieldMappings.*.conversion'] = ['required', 'string'];
        } else {
            $rules['effortCodeField'] = ['required', 'string'];
            $rules['effortCodeSource'] = ['required', 'in:campaign,conversion'];

            if ($this->effortCodeSource === 'campaign') {
                $rules['campaignIntegrationId'] = ['required', 'exists:integrations,id'];
                $rules['campaignDataType'] = ['required', 'string'];
            } else {
                $rules['conversionIntegrationId'] = ['required', 'exists:integrations,id'];
                $rules['conversionDataType'] = ['required', 'string'];
            }
        }

        $this->validate($rules);

        if ($this->type === 'simple') {
            $fieldMappings = [
                'effort_code_field' => $this->effortCodeField,
                'effort_code_source' => $this->effortCodeSource,
            ];
        } else {
            $fieldMappings = $this->fieldMappings;
        }

        $data = [
            'name' => $this->name,
            'type' => $this->type,
            'campaign_integration_id' => $this->campaignIntegrationId ?: null,
            'campaign_data_type' => $this->campaignDataType ?: null,
            'conversion_integration_id' => $this->conversionIntegrationId ?: null,
            'conversion_data_type' => $this->conversionDataType ?: null,
            'is_active' => $this->isActive,
        ];

        if ($this->editingId) {
            $connector = AttributionConnector::forWorkspace($workspace->id)->findOrFail($this->editingId);
            $connector->fill($data);
            $connector->field_mappings = $fieldMappings;
            $connector->save();
        } else {
            $connector = new AttributionConnector($data);
            $connector->workspace_id = $workspace->id;
            $connector->field_mappings = $fieldMappings;
            $connector->save();
        }

        $this->showModal = false;
    }

    public function delete(int $id): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();
        if (! $workspace) {
            return;
        }

        AttributionConnector::forWorkspace($workspace->id)->findOrFail($id)->delete();
    }

    public function getAvailableDataTypesProperty(): array
    {
        return config('integrations.platforms', []);
    }

    public function with(): array
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            return ['connectors' => collect(), 'integrations' => collect()];
        }

        $integrations = Integration::forWorkspace($workspace->id)->active()->orderBy('name')->get();

        return [
            'connectors' => AttributionConnector::forWorkspace($workspace->id)
                ->with(['workspace'])
                ->orderBy('name')
                ->get(),
            'integrations' => $integrations,
        ];
    }
}; ?>

<x-layouts.app>
    <x-slot:title>Connectors</x-slot:title>

    @volt('attribution.connectors')
    <div>
        <div class="flex justify-between items-start mb-6">
            <div>
                <h1 class="text-[22px] font-bold text-slate-900 dark:text-white">Connectors</h1>
                <p class="text-[13px] text-slate-600 dark:text-slate-300 mt-0.5">Map campaign integrations to conversion integrations for attribution matching</p>
            </div>
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                Add Connector
            </flux:button>
        </div>

        <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                @if ($connectors->count() > 0)
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-700">
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Name</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Type</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Campaign Source</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Conversion Source</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Status</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-[12.5px]">
                            @foreach ($connectors as $connector)
                                @php
                                    $campaignIntegration = $integrations->firstWhere('id', $connector->campaign_integration_id);
                                    $conversionIntegration = $integrations->firstWhere('id', $connector->conversion_integration_id);
                                @endphp
                                <tr class="border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-700/30">
                                    <td class="px-3 py-2.5 font-medium text-slate-800 dark:text-slate-200">{{ $connector->name }}</td>
                                    <td class="px-3 py-2.5">
                                        @if ($connector->type === 'mapped')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">Mapped</span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-purple-50 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400">Simple</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 text-slate-600 dark:text-slate-300">
                                        {{ $campaignIntegration?->name ?? '-' }}
                                        @if ($connector->campaign_data_type)
                                            <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300 ml-1">{{ str_replace('_', ' ', $connector->campaign_data_type) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 text-slate-600 dark:text-slate-300">
                                        {{ $conversionIntegration?->name ?? '-' }}
                                        @if ($connector->conversion_data_type)
                                            <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300 ml-1">{{ str_replace('_', ' ', $connector->conversion_data_type) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5">
                                        @if ($connector->is_active)
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
                                        <div class="flex items-center gap-1">
                                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="openEditModal({{ $connector->id }})" title="Edit" />
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="trash"
                                                wire:click="delete({{ $connector->id }})"
                                                wire:confirm="Are you sure you want to delete this connector? Attribution data linked to it may be affected."
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
                        <flux:icon name="link" class="w-10 h-10 text-slate-300 dark:text-slate-600 mx-auto mb-3" />
                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No connectors configured</p>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Create a connector to link campaign and conversion data for attribution</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Create/Edit Connector Modal --}}
        <flux:modal wire:model.self="showModal" class="max-w-lg">
            <div class="space-y-6">
                <flux:heading>{{ $editingId ? 'Edit Connector' : 'Add Connector' }}</flux:heading>

                <form wire:submit="save" class="space-y-4">
                    <flux:input wire:model="name" label="Name" type="text" placeholder="e.g. AC Emails → Voluum Sales" required />

                    <flux:select wire:model.live="type" label="Type">
                        <flux:select.option value="mapped">Mapped (field-to-field matching)</flux:select.option>
                        <flux:select.option value="simple">Simple (effort code lookup)</flux:select.option>
                    </flux:select>

                    @if ($type === 'mapped')
                        {{-- Mapped connector: campaign + conversion integration selects --}}
                        <div class="grid grid-cols-2 gap-4">
                            <flux:select wire:model="campaignIntegrationId" label="Campaign Integration" placeholder="Select...">
                                @foreach ($integrations as $integration)
                                    <flux:select.option value="{{ $integration->id }}">{{ $integration->name }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:input wire:model="campaignDataType" label="Campaign Data Type" type="text" placeholder="e.g. campaign_emails" />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <flux:select wire:model="conversionIntegrationId" label="Conversion Integration" placeholder="Select...">
                                @foreach ($integrations as $integration)
                                    <flux:select.option value="{{ $integration->id }}">{{ $integration->name }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:input wire:model="conversionDataType" label="Conversion Data Type" type="text" placeholder="e.g. conversion_sales" />
                        </div>

                        {{-- Field mappings --}}
                        <div class="space-y-2">
                            <div class="flex justify-between items-center">
                                <p class="text-sm font-medium text-slate-700 dark:text-slate-300">Field Mappings</p>
                                <flux:button type="button" size="xs" variant="ghost" icon="plus" wire:click="addMappingRow">Add Row</flux:button>
                            </div>

                            @foreach ($fieldMappings as $index => $mapping)
                                <div class="flex items-center gap-2">
                                    <flux:input wire:model="fieldMappings.{{ $index }}.campaign" placeholder="Campaign field" class="flex-1" />
                                    <span class="text-slate-400 text-xs shrink-0">→</span>
                                    <flux:input wire:model="fieldMappings.{{ $index }}.conversion" placeholder="Conversion field" class="flex-1" />
                                    <flux:button type="button" size="xs" variant="ghost" icon="x-mark" wire:click="removeMappingRow({{ $index }})" class="text-red-500 shrink-0" />
                                </div>
                            @endforeach

                            @if (empty($fieldMappings))
                                <p class="text-xs text-slate-400 dark:text-slate-500">Click "Add Row" to define field mappings between campaign and conversion data.</p>
                            @endif
                        </div>
                    @else
                        {{-- Simple connector: effort code lookup --}}
                        <flux:select wire:model.live="effortCodeSource" label="Source">
                            <flux:select.option value="campaign">Campaign Integration</flux:select.option>
                            <flux:select.option value="conversion">Conversion Integration</flux:select.option>
                        </flux:select>

                        <div class="grid grid-cols-2 gap-4">
                            @if ($effortCodeSource === 'campaign')
                                <flux:select wire:model="campaignIntegrationId" label="Integration" placeholder="Select...">
                                    @foreach ($integrations as $integration)
                                        <flux:select.option value="{{ $integration->id }}">{{ $integration->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>

                                <flux:input wire:model="campaignDataType" label="Data Type" type="text" placeholder="e.g. campaign_emails" />
                            @else
                                <flux:select wire:model="conversionIntegrationId" label="Integration" placeholder="Select...">
                                    @foreach ($integrations as $integration)
                                        <flux:select.option value="{{ $integration->id }}">{{ $integration->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>

                                <flux:input wire:model="conversionDataType" label="Data Type" type="text" placeholder="e.g. conversion_sales" />
                            @endif
                        </div>

                        <flux:input wire:model="effortCodeField" label="Effort Code Field" type="text" placeholder="Which field contains the effort code?" required />
                    @endif

                    <flux:checkbox wire:model="isActive" label="Active" />

                    <div class="flex justify-end gap-2 pt-2">
                        <flux:button wire:click="$set('showModal', false)" variant="ghost">Cancel</flux:button>
                        <flux:button type="submit" variant="primary">{{ $editingId ? 'Update' : 'Create' }} Connector</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    </div>
    @endvolt
</x-layouts.app>
