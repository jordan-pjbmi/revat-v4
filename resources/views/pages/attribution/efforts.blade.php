<?php

use App\Models\Effort;
use App\Models\Initiative;
use App\Models\Program;
use App\Services\WorkspaceContext;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public bool $showModal = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $code = '';

    public string $selectedProgramId = '';

    public string $initiativeId = '';

    public string $channelType = '';

    public string $status = 'active';

    public ?string $executedAt = null;

    public function openCreateModal(): void
    {
        $this->reset(['editingId', 'name', 'code', 'selectedProgramId', 'initiativeId', 'channelType', 'status', 'executedAt']);
        $this->status = 'active';
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();
        if (! $workspace) {
            return;
        }

        $effort = Effort::forWorkspace($workspace->id)->with('initiative')->findOrFail($id);
        $this->editingId = $effort->id;
        $this->name = $effort->name;
        $this->code = $effort->code;
        $this->initiativeId = (string) $effort->initiative_id;
        $this->selectedProgramId = (string) ($effort->initiative?->program_id ?? '');
        $this->channelType = $effort->channel_type ?? '';
        $this->status = $effort->status;
        $this->executedAt = $effort->executed_at?->format('Y-m-d');
        $this->showModal = true;
    }

    public function updatedSelectedProgramId(): void
    {
        $this->initiativeId = '';
    }

    public function save(): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();
        if (! $workspace) {
            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('efforts', 'code')
                    ->where('workspace_id', $workspace->id)
                    ->whereNull('deleted_at')
                    ->ignore($this->editingId),
            ],
            'initiativeId' => ['required', 'exists:initiatives,id'],
            'channelType' => ['nullable', 'string', 'max:100'],
            'status' => ['required', 'in:active,paused,completed'],
            'executedAt' => ['nullable', 'date'],
        ]);

        $data = [
            'name' => $this->name,
            'code' => $this->code,
            'initiative_id' => $this->initiativeId,
            'channel_type' => $this->channelType ?: null,
            'status' => $this->status,
            'executed_at' => $this->executedAt ?: null,
        ];

        if ($this->editingId) {
            $effort = Effort::forWorkspace($workspace->id)->findOrFail($this->editingId);
            $effort->update($data);
        } else {
            $data['workspace_id'] = $workspace->id;
            Effort::create($data);
        }

        $this->showModal = false;
    }

    public function delete(int $id): void
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();
        if (! $workspace) {
            return;
        }

        $effort = Effort::forWorkspace($workspace->id)->findOrFail($id);
        $effort->delete();
    }

    public function getFilteredInitiativesProperty(): \Illuminate\Support\Collection
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();
        if (! $workspace) {
            return collect();
        }

        $query = Initiative::forWorkspace($workspace->id)->orderBy('name');

        if ($this->selectedProgramId) {
            $query->where('program_id', $this->selectedProgramId);
        }

        return $query->get();
    }

    public function with(): array
    {
        $workspace = app(WorkspaceContext::class)->getWorkspace();

        if (! $workspace) {
            return ['efforts' => collect(), 'programs' => collect()];
        }

        return [
            'efforts' => Effort::forWorkspace($workspace->id)
                ->with('initiative.program')
                ->orderBy('name')
                ->get(),
            'programs' => Program::forWorkspace($workspace->id)->orderBy('name')->get(),
        ];
    }
}; ?>

<x-layouts.app>
    <x-slot:title>Efforts</x-slot:title>

    @volt('attribution.efforts')
    <div>
        <div class="flex justify-between items-start mb-6">
            <div>
                <h1 class="text-[22px] font-bold text-slate-900 dark:text-white">Efforts</h1>
                <p class="text-[13px] text-slate-600 dark:text-slate-300 mt-0.5">Leaf-level execution units that receive attribution credit</p>
            </div>
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                Add Effort
            </flux:button>
        </div>

        <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                @if ($efforts->count() > 0)
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-700">
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Name</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Code</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Initiative</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Program</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Channel Type</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Status</th>
                                <th class="text-[10.5px] font-semibold uppercase tracking-[0.4px] text-slate-400 px-3 py-[11px]">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-[12.5px]">
                            @foreach ($efforts as $effort)
                                <tr class="border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-700/30">
                                    <td class="px-3 py-2.5 font-medium text-slate-800 dark:text-slate-200">{{ $effort->name }}</td>
                                    <td class="px-3 py-2.5 font-mono text-slate-500">{{ $effort->code }}</td>
                                    <td class="px-3 py-2.5 text-slate-600 dark:text-slate-300">{{ $effort->initiative?->name ?? '-' }}</td>
                                    <td class="px-3 py-2.5 text-slate-600 dark:text-slate-300">{{ $effort->initiative?->program?->name ?? '-' }}</td>
                                    <td class="px-3 py-2.5 text-slate-600 dark:text-slate-300">
                                        @if ($effort->channel_type)
                                            <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300">{{ $effort->channel_type }}</span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5">
                                        @if ($effort->status === 'active')
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                Active
                                            </span>
                                        @elseif ($effort->status === 'paused')
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                                Paused
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-400">
                                                <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                                                Completed
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <div class="flex items-center gap-1">
                                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="openEditModal({{ $effort->id }})" title="Edit" />
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="trash"
                                                wire:click="delete({{ $effort->id }})"
                                                wire:confirm="Are you sure you want to delete this effort?"
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
                        <flux:icon name="bolt" class="w-10 h-10 text-slate-300 dark:text-slate-600 mx-auto mb-3" />
                        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No efforts yet</p>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Create an effort within an initiative to track individual marketing actions</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Create/Edit Effort Modal --}}
        <flux:modal wire:model.self="showModal" class="max-w-lg">
            <div class="space-y-6">
                <flux:heading>{{ $editingId ? 'Edit Effort' : 'Add Effort' }}</flux:heading>

                <form wire:submit="save" class="space-y-4">
                    <flux:input wire:model="name" label="Name" type="text" placeholder="e.g. March Welcome Email" required />
                    <flux:input wire:model="code" label="Code" type="text" placeholder="e.g. MAR-WELCOME" required />

                    <flux:select wire:model.live="selectedProgramId" label="Filter by Program" placeholder="All programs">
                        <flux:select.option value="">All programs</flux:select.option>
                        @foreach ($programs as $program)
                            <flux:select.option value="{{ $program->id }}">{{ $program->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="initiativeId" label="Initiative" placeholder="Select an initiative...">
                        @foreach ($this->filteredInitiatives as $initiative)
                            <flux:select.option value="{{ $initiative->id }}">{{ $initiative->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="channelType" label="Channel Type" type="text" placeholder="e.g. email, sms, paid_search" />

                    <flux:select wire:model="status" label="Status">
                        <flux:select.option value="active">Active</flux:select.option>
                        <flux:select.option value="paused">Paused</flux:select.option>
                        <flux:select.option value="completed">Completed</flux:select.option>
                    </flux:select>

                    <flux:input wire:model="executedAt" label="Executed At" type="date" />

                    <div class="flex justify-end gap-2 pt-2">
                        <flux:button wire:click="$set('showModal', false)" variant="ghost">Cancel</flux:button>
                        <flux:button type="submit" variant="primary">{{ $editingId ? 'Update' : 'Create' }} Effort</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    </div>
    @endvolt
</x-layouts.app>
