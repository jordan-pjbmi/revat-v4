<?php

use App\Models\User;
use App\Models\Workspace;
use Livewire\Volt\Component;
use Spatie\Permission\PermissionRegistrar;

new class extends Component
{
    public Workspace $workspace;
    public string $search = '';
    public array $selectedUserIds = [];
    public array $addUserIds = [];
    public bool $showAddDropdown = false;
    public bool $showCopyFrom = false;
    public ?int $copySourceId = null;
    public array $copyRoles = ['editor', 'viewer'];

    public function mount(Workspace $workspace): void
    {
        $this->workspace = $workspace;
        $org = auth()->user()->currentOrganization;
        if ($workspace->organization_id !== $org->id) {
            abort(403);
        }
    }

    public function getAdminUsers(): \Illuminate\Support\Collection
    {
        $org = auth()->user()->currentOrganization;
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);

        return $org->users()->get()->filter(function ($user) {
            $user->unsetRelation('roles');
            return $user->hasRole(['owner', 'admin']);
        })->map(function ($user) {
            $user->unsetRelation('roles');
            return (object) [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->roles->first()?->name ?? 'admin',
                'user' => $user,
            ];
        })->values();
    }

    public function getWorkspaceMembers(): \Illuminate\Database\Eloquent\Collection
    {
        $query = $this->workspace->users();

        $org = auth()->user()->currentOrganization;
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $adminIds = $org->users()->get()->filter(function ($user) {
            $user->unsetRelation('roles');
            return $user->hasRole(['owner', 'admin']);
        })->pluck('id');

        $query->whereNotIn('users.id', $adminIds);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('users.name', 'like', "%{$this->search}%")
                  ->orWhere('users.email', 'like', "%{$this->search}%");
            });
        }

        return $query->get();
    }

    public function getAvailableUsers(): \Illuminate\Support\Collection
    {
        $org = auth()->user()->currentOrganization;
        $existingIds = $this->workspace->users()->pluck('users.id');

        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);

        return $org->users()
            ->whereNotIn('users.id', $existingIds)
            ->whereNull('users.deactivated_at')
            ->get()
            ->filter(function ($user) {
                $user->unsetRelation('roles');
                return ! $user->hasRole(['owner', 'admin']);
            })
            ->values();
    }

    public function addUsers(): void
    {
        $org = auth()->user()->currentOrganization;
        $validIds = $org->users()->whereIn('users.id', $this->addUserIds)->pluck('users.id');
        $existingIds = $this->workspace->users()->pluck('users.id');
        $newIds = $validIds->diff($existingIds);

        foreach ($newIds as $id) {
            $this->workspace->users()->attach($id);
        }

        $this->addUserIds = [];
        $this->showAddDropdown = false;
    }

    public function removeUsers(): void
    {
        $this->workspace->users()->detach($this->selectedUserIds);
        $this->selectedUserIds = [];
    }

    public function removeUser(int $userId): void
    {
        $this->workspace->users()->detach($userId);
    }

    public function getOtherWorkspaces(): \Illuminate\Database\Eloquent\Collection
    {
        $org = auth()->user()->currentOrganization;
        return $org->workspaces()
            ->where('id', '!=', $this->workspace->id)
            ->orderBy('name')
            ->get();
    }

    public function copyFromWorkspace(): void
    {
        $org = auth()->user()->currentOrganization;
        $source = $org->workspaces()->findOrFail($this->copySourceId);
        $roles = $this->copyRoles;

        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $existingIds = $this->workspace->users()->pluck('users.id');

        $usersToAdd = $source->users()
            ->whereNotIn('users.id', $existingIds)
            ->whereNull('users.deactivated_at')
            ->get()
            ->filter(function ($user) use ($roles) {
                $user->unsetRelation('roles');
                $userRole = $user->roles->first()?->name ?? 'viewer';
                return in_array($userRole, $roles) && ! $user->hasRole(['owner', 'admin']);
            });

        foreach ($usersToAdd as $user) {
            $this->workspace->users()->attach($user->id);
        }

        $this->showCopyFrom = false;
        $this->copySourceId = null;
        $this->copyRoles = ['editor', 'viewer'];
    }
}; ?>

<x-layouts.app>
    <x-slot:title>Workspace Users — {{ $workspace->name }}</x-slot:title>

    <div class="max-w-4xl mx-auto">
        <h1 class="text-xl font-bold text-zinc-900 dark:text-white mb-1">Settings</h1>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">Manage your account settings and preferences.</p>

        <x-settings-tabs active="workspaces" />

        @volt('settings.workspaces.users')
        <div class="mt-6">
            {{-- Header --}}
            <div class="mb-4">
                <a href="{{ route('settings.workspaces') }}" class="text-sm text-blue-600 hover:text-blue-500">&larr; Back to workspaces</a>
                <h2 class="text-[17px] font-semibold text-zinc-900 dark:text-white mt-1">{{ $workspace->name }}</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $workspace->totalMemberCount() }} members
                    @if ($workspace->is_default) &middot; Default workspace @endif
                </p>
            </div>

            {{-- Admins Section --}}
            @php $admins = $this->getAdminUsers(); @endphp
            @if ($admins->isNotEmpty())
                <div class="mb-6">
                    <div class="flex items-center gap-2 mb-2">
                        <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Admins</h3>
                        <span class="text-xs text-zinc-400 bg-zinc-100 dark:bg-zinc-700 px-2 py-0.5 rounded">Access via role</span>
                    </div>
                    <p class="text-xs text-zinc-400 mb-3">These users have access to all workspaces via their organization role</p>

                    <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl overflow-hidden">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Name</flux:table.column>
                                <flux:table.column>Email</flux:table.column>
                                <flux:table.column>Role</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach ($admins as $admin)
                                    <flux:table.row>
                                        <flux:table.cell>
                                            <div class="flex items-center gap-2.5">
                                                <x-user-avatar :user="$admin->user" />
                                                <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $admin->name }}</span>
                                            </div>
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $admin->email }}</span>
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            <x-role-badge :role="$admin->role" />
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                </div>
            @endif

            {{-- Members Section --}}
            <div>
                <div class="flex justify-between items-center mb-3">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Members</h3>
                    <div class="flex gap-2">
                        <flux:button wire:click="$set('showCopyFrom', true)" variant="ghost" size="sm">
                            Copy from...
                        </flux:button>
                        <flux:button wire:click="$set('showAddDropdown', true)" variant="primary" size="sm" icon="plus">
                            Add Members
                        </flux:button>
                    </div>
                </div>

                {{-- Add Members Dropdown --}}
                @if ($showAddDropdown)
                    <div class="mb-4 p-4 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl">
                        <div class="mb-3">
                            <flux:label>Select members to add</flux:label>
                            @php $available = $this->getAvailableUsers(); @endphp
                            @if ($available->isEmpty())
                                <p class="text-sm text-zinc-400 mt-2">All organization members are already in this workspace.</p>
                            @else
                                <div class="mt-2 max-h-48 overflow-y-auto border border-zinc-200 dark:border-zinc-600 rounded-lg">
                                    @foreach ($available as $user)
                                        <label class="flex items-center gap-3 px-3 py-2 hover:bg-zinc-50 dark:hover:bg-zinc-700 cursor-pointer">
                                            <input type="checkbox" wire:model="addUserIds" value="{{ $user->id }}" class="rounded border-zinc-300 dark:border-zinc-600 text-blue-600">
                                            <x-user-avatar :user="$user" size="size-6" />
                                            <div>
                                                <span class="text-sm text-zinc-900 dark:text-white">{{ $user->name }}</span>
                                                <span class="text-xs text-zinc-400 ml-1">{{ $user->email }}</span>
                                            </div>
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="flex justify-end gap-2">
                            <flux:button wire:click="$set('showAddDropdown', false)" variant="ghost" size="sm">Cancel</flux:button>
                            @if ($available->isNotEmpty())
                                <flux:button wire:click="addUsers" variant="primary" size="sm">Add Selected</flux:button>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Copy From Workspace --}}
                @if ($showCopyFrom)
                    <div class="mb-4 p-4 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl">
                        <div class="mb-3">
                            <flux:select wire:model.live="copySourceId" label="Copy members from" placeholder="Select a workspace...">
                                @foreach ($this->getOtherWorkspaces() as $ws)
                                    <flux:select.option value="{{ $ws->id }}">{{ $ws->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                        @if ($copySourceId)
                            <div class="mb-3">
                                <flux:label>Filter by role</flux:label>
                                <div class="flex gap-4 mt-1">
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" wire:model="copyRoles" value="editor" class="rounded border-zinc-300 dark:border-zinc-600 text-blue-600">
                                        <span class="text-sm text-zinc-700 dark:text-zinc-300">Editors</span>
                                    </label>
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" wire:model="copyRoles" value="viewer" class="rounded border-zinc-300 dark:border-zinc-600 text-blue-600">
                                        <span class="text-sm text-zinc-700 dark:text-zinc-300">Viewers</span>
                                    </label>
                                </div>
                            </div>
                        @endif
                        <div class="flex justify-end gap-2">
                            <flux:button wire:click="$set('showCopyFrom', false)" variant="ghost" size="sm">Cancel</flux:button>
                            @if ($copySourceId)
                                <flux:button wire:click="copyFromWorkspace" variant="primary" size="sm">Copy Members</flux:button>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Search --}}
                <div class="mb-3">
                    <flux:input wire:model.live.debounce.300ms="search" type="text" placeholder="Search members by name or email..." icon="magnifying-glass" size="sm" />
                </div>

                {{-- Bulk Action Bar --}}
                @if (count($selectedUserIds) > 0)
                    <div class="mb-3 px-4 py-2 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg flex justify-between items-center">
                        <span class="text-sm text-blue-700 dark:text-blue-300">{{ count($selectedUserIds) }} member(s) selected</span>
                        <flux:button wire:click="removeUsers" variant="danger" size="xs">Remove Selected</flux:button>
                    </div>
                @endif

                {{-- Members Table --}}
                @php $members = $this->getWorkspaceMembers(); @endphp
                <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl overflow-hidden">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column class="w-8"></flux:table.column>
                            <flux:table.column>Name</flux:table.column>
                            <flux:table.column>Email</flux:table.column>
                            <flux:table.column>Role</flux:table.column>
                            <flux:table.column>Added</flux:table.column>
                            <flux:table.column></flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($members as $user)
                                @php
                                    app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId(auth()->user()->current_organization_id);
                                    $user->unsetRelation('roles');
                                    $userRole = $user->roles->first()?->name ?? 'viewer';
                                @endphp
                                <flux:table.row>
                                    <flux:table.cell>
                                        <input type="checkbox" wire:model="selectedUserIds" value="{{ $user->id }}" class="rounded border-zinc-300 dark:border-zinc-600 text-blue-600">
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <div class="flex items-center gap-2.5">
                                            <x-user-avatar :user="$user" />
                                            <span class="text-sm font-medium {{ $user->isDeactivated() ? 'text-zinc-400' : 'text-zinc-900 dark:text-white' }}">{{ $user->name }}</span>
                                            @if ($user->isDeactivated())
                                                <span class="text-xs text-zinc-400 bg-zinc-100 dark:bg-zinc-700 px-1.5 py-0.5 rounded">Deactivated</span>
                                            @endif
                                        </div>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $user->email }}</span>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <x-role-badge :role="$userRole" />
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <span class="text-sm text-zinc-400">{{ $user->pivot->created_at?->format('M j') }}</span>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <flux:button wire:click="removeUser({{ $user->id }})" variant="ghost" size="xs" icon="x-mark" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="6" class="text-center">
                                        <span class="text-sm text-zinc-400">No members assigned to this workspace.</span>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        </div>
        @endvolt
    </div>
</x-layouts.app>
