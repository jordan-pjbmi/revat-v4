<?php

use App\Models\Workspace;
use App\Services\PlanEnforcement\WorkspaceLimitService;
use Livewire\Volt\Component;

new class extends Component
{
    public string $newWorkspaceName = '';

    public ?int $editingWorkspaceId = null;

    public string $editWorkspaceName = '';

    public ?int $confirmingDeleteId = null;

    public bool $showCreateForm = false;

    public function getWorkspaces(): \Illuminate\Database\Eloquent\Collection
    {
        return auth()->user()->currentOrganization->workspaces()->withCount('users')->orderBy('name')->get();
    }

    public function getLimitInfo(): array
    {
        $service = app(WorkspaceLimitService::class);
        $org = auth()->user()->currentOrganization;

        return [
            'canAdd' => $service->canAdd($org),
            'current' => $service->currentCount($org),
            'max' => $service->maxAllowed($org),
        ];
    }

    public function createWorkspace(): void
    {
        $this->validate([
            'newWorkspaceName' => ['required', 'string', 'max:255'],
        ]);

        $org = auth()->user()->currentOrganization;
        $service = app(WorkspaceLimitService::class);

        if (! $service->canAdd($org)) {
            $this->addError('newWorkspaceName', 'Workspace limit reached. Upgrade your plan to add more workspaces.');

            return;
        }

        // Validate uniqueness within org
        if ($org->workspaces()->where('name', $this->newWorkspaceName)->exists()) {
            $this->addError('newWorkspaceName', 'A workspace with this name already exists.');

            return;
        }

        $workspace = new Workspace(['name' => $this->newWorkspaceName]);
        $workspace->organization_id = $org->id;
        $workspace->is_default = false;
        $workspace->save();

        $this->newWorkspaceName = '';
        $this->showCreateForm = false;
    }

    public function startEditing(int $workspaceId): void
    {
        $workspace = Workspace::findOrFail($workspaceId);
        $this->editingWorkspaceId = $workspaceId;
        $this->editWorkspaceName = $workspace->name;
    }

    public function saveEdit(): void
    {
        $this->validate([
            'editWorkspaceName' => ['required', 'string', 'max:255'],
        ]);

        $org = auth()->user()->currentOrganization;
        $workspace = $org->workspaces()->findOrFail($this->editingWorkspaceId);

        // Validate uniqueness within org
        if ($org->workspaces()->where('name', $this->editWorkspaceName)->where('id', '!=', $workspace->id)->exists()) {
            $this->addError('editWorkspaceName', 'A workspace with this name already exists.');

            return;
        }

        $workspace->update(['name' => $this->editWorkspaceName]);
        $this->editingWorkspaceId = null;
        $this->editWorkspaceName = '';
    }

    public function confirmDelete(int $workspaceId): void
    {
        $this->confirmingDeleteId = $workspaceId;
    }

    public function deleteWorkspace(): void
    {
        $org = auth()->user()->currentOrganization;
        $workspace = $org->workspaces()->findOrFail($this->confirmingDeleteId);

        if ($workspace->is_default) {
            $this->addError('delete', 'Cannot delete the default workspace.');
            $this->confirmingDeleteId = null;

            return;
        }

        $workspace->delete();
        $this->confirmingDeleteId = null;
    }
}; ?>

<x-layouts.app>
    <x-slot:title>Workspace Settings</x-slot:title>

    <div class="max-w-4xl mx-auto">
        <h1 class="text-xl font-bold text-zinc-900 dark:text-white mb-1">Settings</h1>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">Manage your account settings and preferences.</p>

        <x-settings-tabs active="workspaces" />

        @volt('settings.workspaces.index')
        <div class="mt-6">
            {{-- Header --}}
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-[17px] font-semibold text-zinc-900 dark:text-white">Workspaces</h2>
                @php $limitInfo = $this->getLimitInfo(); @endphp
                @if ($limitInfo['canAdd'])
                    <flux:button wire:click="$set('showCreateForm', true)" variant="primary" size="sm" icon="plus">
                        Create Workspace
                    </flux:button>
                @else
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">
                        Workspace limit reached ({{ $limitInfo['current'] }} of {{ $limitInfo['max'] }}).
                        <a href="{{ route('billing.subscribe') }}" class="text-blue-600 hover:text-blue-500">Upgrade your plan</a>
                    </div>
                @endif
            </div>

            {{-- Create Form --}}
            @if ($showCreateForm)
                <div class="mb-4 p-4 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl">
                    <form wire:submit="createWorkspace" class="flex items-end gap-3">
                        <div class="flex-1">
                            <flux:input
                                wire:model="newWorkspaceName"
                                label="Workspace name"
                                type="text"
                                placeholder="Enter workspace name"
                                required
                            />
                        </div>
                        <flux:button type="submit" variant="primary" size="sm">Create</flux:button>
                        <flux:button wire:click="$set('showCreateForm', false)" variant="ghost" size="sm">Cancel</flux:button>
                    </form>
                </div>
            @endif

            {{-- Workspaces Table --}}
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl overflow-hidden">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Name</flux:table.column>
                        <flux:table.column>Members</flux:table.column>
                        <flux:table.column>Default</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->getWorkspaces() as $workspace)
                            <flux:table.row>
                                <flux:table.cell>
                                    @if ($editingWorkspaceId === $workspace->id)
                                        <form wire:submit="saveEdit" class="flex items-center gap-2">
                                            <flux:input wire:model="editWorkspaceName" type="text" class="!py-1" required />
                                            <flux:button type="submit" variant="primary" size="xs">Save</flux:button>
                                            <flux:button wire:click="$set('editingWorkspaceId', null)" variant="ghost" size="xs">Cancel</flux:button>
                                        </form>
                                    @else
                                        <a href="{{ route('settings.workspaces.users', $workspace) }}" class="text-sm font-medium text-zinc-900 dark:text-white hover:text-blue-600 dark:hover:text-blue-400">
                                            {{ $workspace->name }}
                                        </a>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    <a href="{{ route('settings.workspaces.users', $workspace) }}" class="text-sm text-zinc-500 dark:text-zinc-400 hover:text-blue-600 dark:hover:text-blue-400">
                                        {{ $workspace->totalMemberCount() }}
                                    </a>
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($workspace->is_default)
                                        <flux:badge size="sm">Default</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:dropdown>
                                        <flux:button variant="ghost" icon="ellipsis-horizontal" size="sm" />
                                        <flux:menu>
                                            <flux:menu.item wire:click="startEditing({{ $workspace->id }})">
                                                Rename
                                            </flux:menu.item>
                                            <flux:menu.item href="{{ route('settings.workspaces.users', $workspace) }}">
                                                Manage Users
                                            </flux:menu.item>
                                            @if (! $workspace->is_default)
                                                <flux:menu.separator />
                                                <flux:menu.item variant="danger" wire:click="confirmDelete({{ $workspace->id }})">
                                                    Delete
                                                </flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            {{-- Delete Confirmation Modal --}}
            <flux:modal wire:model.self="confirmingDeleteId" class="max-w-sm">
                <div class="space-y-4">
                    <flux:heading>Delete Workspace</flux:heading>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Are you sure you want to delete this workspace? All associated integrations, extraction records, and attribution results will be permanently deleted.
                    </p>
                    @error('delete')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    <div class="flex justify-end gap-2">
                        <flux:button wire:click="$set('confirmingDeleteId', null)" variant="ghost">Cancel</flux:button>
                        <flux:button wire:click="deleteWorkspace" variant="danger">Delete</flux:button>
                    </div>
                </div>
            </flux:modal>
        </div>
        @endvolt
    </div>
</x-layouts.app>
