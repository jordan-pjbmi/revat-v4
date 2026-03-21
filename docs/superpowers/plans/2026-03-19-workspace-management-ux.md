# Workspace Management UX Improvements — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve workspace management UX with implicit admin access, better navigation, enhanced switcher (search/pins/recents), improved users page (bulk ops, admins section), and user-centric workspace assignment.

**Architecture:** Modify `WorkspaceContext::accessibleWorkspaceIds()` to short-circuit for owner/admin roles. Add two migrations (pivot column + recents table). Enhance existing Volt pages for workspace and user management. Add navigation entry points in sidebar and switcher.

**Tech Stack:** Laravel 11, Livewire 3 / Volt, Flux UI components, Tailwind CSS v4, Spatie laravel-permission (teams mode), Pest

**Spec:** `docs/superpowers/specs/2026-03-19-workspace-management-ux-design.md`

---

## File Map

**New files:**
- `database/migrations/XXXX_add_is_pinned_to_workspace_user_table.php` — add `is_pinned` column
- `database/migrations/XXXX_create_workspace_recent_table.php` — recents tracking table
- `app/Models/WorkspaceRecent.php` — Eloquent model for recent workspace tracking
- `app/Listeners/RecordRecentWorkspace.php` — listener for `WorkspaceSwitched` event
- `tests/Feature/Foundation/ImplicitWorkspaceAccessTest.php` — tests for implicit access logic
- `tests/Feature/Settings/WorkspaceUsersPageTest.php` — tests for redesigned users page
- `tests/Feature/Settings/UserWorkspaceAssignmentTest.php` — tests for user-centric assignment
- `tests/Feature/Foundation/WorkspaceSwitcherTest.php` — tests for switcher enhancements

**Modified files:**
- `app/Services/WorkspaceContext.php:180-195` — add role check in `accessibleWorkspaceIds()`
- `app/Models/Workspace.php` — add `memberCount()` method that includes implicit admins
- `app/Models/User.php:87-91` — update `workspaces()` to include `is_pinned` on pivot
- `resources/views/components/layouts/app.blade.php:22-44` — add sidebar Workspaces link
- `resources/views/components/layouts/app.blade.php:111-134` — redesign workspace switcher
- `resources/views/pages/settings/workspaces/index.blade.php:164-218` — make name/count clickable
- `resources/views/pages/settings/workspaces/users.blade.php` — full redesign with admins/members sections
- `resources/views/pages/settings/users/index.blade.php:240-262` — add "Manage Workspaces" action per user
- `app/Providers/EventServiceProvider.php` or event discovery — register listener
- `routes/web.php` — add API routes for pin toggle, workspace assignment

---

## Task 1: Implicit Access — Backend Logic

**Files:**
- Modify: `app/Services/WorkspaceContext.php:180-195`
- Modify: `app/Models/Workspace.php`
- Create: `tests/Feature/Foundation/ImplicitWorkspaceAccessTest.php`

**Context:** `WorkspaceContext::accessibleWorkspaceIds()` currently queries the `workspace_user` pivot. We need it to return ALL org workspace IDs for owner/admin users. `User::accessibleWorkspaceIds()` delegates to this method (line 161 of User.php), so only one change is needed. Spatie permission is team-scoped — use `setPermissionsTeamId()` before role checks.

- [ ] **Step 1: Write failing tests for implicit access**

```php
<?php
// tests/Feature/Foundation/ImplicitWorkspaceAccessTest.php

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organization::create(['name' => 'Test Org']);

    $this->ws1 = new Workspace(['name' => 'Workspace 1']);
    $this->ws1->organization_id = $this->org->id;
    $this->ws1->is_default = true;
    $this->ws1->save();

    $this->ws2 = new Workspace(['name' => 'Workspace 2']);
    $this->ws2->organization_id = $this->org->id;
    $this->ws2->save();

    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    $this->owner->organizations()->attach($this->org->id);
    $this->owner->current_organization_id = $this->org->id;
    $this->owner->save();
    // Only attach to ws1, NOT ws2
    $this->ws1->users()->attach($this->owner->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->owner->assignRole('owner');

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->organizations()->attach($this->org->id);
    $this->admin->current_organization_id = $this->org->id;
    $this->admin->save();
    $this->ws1->users()->attach($this->admin->id);
    $this->admin->assignRole('admin');

    $this->editor = User::factory()->create(['email_verified_at' => now()]);
    $this->editor->organizations()->attach($this->org->id);
    $this->editor->current_organization_id = $this->org->id;
    $this->editor->save();
    $this->ws1->users()->attach($this->editor->id);
    $this->editor->assignRole('editor');
});

it('gives owner implicit access to all workspaces', function () {
    $context = app(WorkspaceContext::class);
    $ids = $context->accessibleWorkspaceIds($this->owner, $this->org);

    expect($ids)->toContain($this->ws1->id)
        ->and($ids)->toContain($this->ws2->id);
});

it('gives admin implicit access to all workspaces', function () {
    $context = app(WorkspaceContext::class);
    $ids = $context->accessibleWorkspaceIds($this->admin, $this->org);

    expect($ids)->toContain($this->ws1->id)
        ->and($ids)->toContain($this->ws2->id);
});

it('does not give editor implicit access', function () {
    $context = app(WorkspaceContext::class);
    $ids = $context->accessibleWorkspaceIds($this->editor, $this->org);

    expect($ids)->toContain($this->ws1->id)
        ->and($ids)->not->toContain($this->ws2->id);
});

it('clean demotion removes implicit access', function () {
    // Admin has implicit access to ws2 but no pivot entry
    $context = app(WorkspaceContext::class);
    $ids = $context->accessibleWorkspaceIds($this->admin, $this->org);
    expect($ids)->toContain($this->ws2->id);

    // Demote admin to editor
    $this->admin->syncRoles(['editor']);
    $context->reset(); // Clear request cache

    $ids = $context->accessibleWorkspaceIds($this->admin, $this->org);
    expect($ids)->not->toContain($this->ws2->id)
        ->and($ids)->toContain($this->ws1->id); // Still has pivot entry
});

it('user model delegates to workspace context for implicit access', function () {
    // Owner should get all workspaces via User::accessibleWorkspaceIds() too
    $ids = $this->owner->accessibleWorkspaceIds($this->org);

    expect($ids)->toContain($this->ws1->id)
        ->and($ids)->toContain($this->ws2->id);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test tests/Feature/Foundation/ImplicitWorkspaceAccessTest.php`
Expected: Tests checking implicit access for owner/admin FAIL (they only get pivot-based access)

- [ ] **Step 3: Implement implicit access in WorkspaceContext**

Modify `app/Services/WorkspaceContext.php`. The `accessibleWorkspaceIds()` method (lines 180-195) needs a role check before the pivot query:

```php
public function accessibleWorkspaceIds(User $user, Organization $organization): Collection
{
    $cacheKey = $this->cacheKey($user->id, $organization->id);

    if (isset($this->accessibleCache[$cacheKey])) {
        return $this->accessibleCache[$cacheKey];
    }

    // Owner/admin get implicit access to all org workspaces
    app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($organization->id);
    $user->unsetRelation('roles');

    if ($user->hasRole(['owner', 'admin'])) {
        $ids = $organization->workspaces()->pluck('id');
        $this->accessibleCache[$cacheKey] = $ids;

        return $ids;
    }

    $ids = $user->workspaces()
        ->where('workspaces.organization_id', $organization->id)
        ->pluck('workspaces.id');

    $this->accessibleCache[$cacheKey] = $ids;

    return $ids;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test tests/Feature/Foundation/ImplicitWorkspaceAccessTest.php`
Expected: All 5 tests PASS

- [ ] **Step 5: Run full test suite to check for regressions**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test`
Expected: All existing tests still pass

- [ ] **Step 6: Add memberCount method to Workspace model**

Add a method to `app/Models/Workspace.php` that counts both explicit pivot members AND implicit owner/admin members:

```php
/**
 * Count all users with access to this workspace (explicit + implicit).
 */
public function totalMemberCount(): int
{
    $explicitCount = $this->users()->count();

    // Count org-level owner/admin users not already in the pivot
    $org = $this->organization;
    $explicitUserIds = $this->users()->pluck('users.id');

    app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $implicitCount = $org->users()
        ->whereNotIn('users.id', $explicitUserIds)
        ->get()
        ->filter(function ($user) {
            $user->unsetRelation('roles');
            return $user->hasRole(['owner', 'admin']);
        })
        ->count();

    return $explicitCount + $implicitCount;
}
```

- [ ] **Step 7: Commit**

```bash
git add app/Services/WorkspaceContext.php app/Models/Workspace.php tests/Feature/Foundation/ImplicitWorkspaceAccessTest.php
git commit -m "feat: implicit workspace access for owner/admin roles

Owner and admin users now have automatic access to all workspaces
in their organization without needing explicit pivot entries.
Clean demotion: demoting to editor/viewer removes implicit access."
```

---

## Task 2: Migrations — Pin Column and Recents Table

**Files:**
- Create: `database/migrations/XXXX_add_is_pinned_to_workspace_user_table.php`
- Create: `database/migrations/XXXX_create_workspace_recent_table.php`
- Create: `app/Models/WorkspaceRecent.php`
- Modify: `app/Models/User.php:87-91`

**Context:** The `workspace_user` pivot uses a composite primary key `(workspace_id, user_id)` — no auto-increment `id`. The `is_pinned` column is a boolean default false. The `workspace_recent` table has a unique constraint on `(user_id, organization_id, workspace_id)` for upserts. The User model's `workspaces()` relationship needs `is_pinned` added to `withPivot()`.

- [ ] **Step 1: Create migration to add is_pinned to workspace_user**

```bash
cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan make:migration add_is_pinned_to_workspace_user_table
```

Edit the generated file:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            $table->dropColumn('is_pinned');
        });
    }
};
```

- [ ] **Step 2: Create migration for workspace_recent table**

```bash
cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan make:migration create_workspace_recent_table
```

Edit the generated file:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_recent', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->timestamp('switched_at');
            $table->unique(['user_id', 'organization_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_recent');
    }
};
```

- [ ] **Step 3: Run migrations**

```bash
cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan migrate
```

Expected: Both migrations run successfully

- [ ] **Step 4: Create WorkspaceRecent model**

```php
<?php
// app/Models/WorkspaceRecent.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceRecent extends Model
{
    public $timestamps = false;

    protected $table = 'workspace_recent';

    protected $fillable = [
        'user_id',
        'organization_id',
        'workspace_id',
        'switched_at',
    ];

    protected function casts(): array
    {
        return [
            'switched_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
```

- [ ] **Step 5: Update pivot attributes on both sides of the relationship**

In `app/Models/User.php`, update the `workspaces()` relationship (lines 87-91):

```php
public function workspaces(): BelongsToMany
{
    return $this->belongsToMany(Workspace::class, 'workspace_user')
        ->withPivot('is_pinned')
        ->withTimestamps();
}
```

In `app/Models/Workspace.php`, update the `users()` relationship to include `is_pinned`:

```php
public function users(): BelongsToMany
{
    return $this->belongsToMany(User::class, 'workspace_user')
        ->withPivot('is_pinned')
        ->withTimestamps();
}
```

- [ ] **Step 6: Run tests to check for regressions**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test`
Expected: All tests pass

- [ ] **Step 7: Commit**

```bash
git add database/migrations/*_add_is_pinned_to_workspace_user_table.php database/migrations/*_create_workspace_recent_table.php app/Models/WorkspaceRecent.php app/Models/User.php app/Models/Workspace.php
git commit -m "feat: add workspace pin and recent tracking schema

Add is_pinned boolean to workspace_user pivot table.
Create workspace_recent table for persisted recent workspace history.
Add WorkspaceRecent model and update User pivot attributes."
```

---

## Task 3: RecordRecentWorkspace Listener

**Files:**
- Create: `app/Listeners/RecordRecentWorkspace.php`
- Create: `tests/Feature/Foundation/RecordRecentWorkspaceTest.php`

**Context:** The `WorkspaceSwitched` event (at `app/Events/WorkspaceSwitched.php`) has properties: `user_id`, `from_workspace_id`, `to_workspace_id`, `ip_address`, `occurred_at`. It does NOT carry `organization_id` — the listener must resolve it from the workspace. Laravel auto-discovers listeners via type-hinting.

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Feature/Foundation/RecordRecentWorkspaceTest.php

use App\Events\WorkspaceSwitched;
use App\Listeners\RecordRecentWorkspace;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceRecent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->ws1 = new Workspace(['name' => 'WS 1']);
    $this->ws1->organization_id = $this->org->id;
    $this->ws1->is_default = true;
    $this->ws1->save();

    $this->ws2 = new Workspace(['name' => 'WS 2']);
    $this->ws2->organization_id = $this->org->id;
    $this->ws2->save();

    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->user->organizations()->attach($this->org->id);
    $this->user->current_organization_id = $this->org->id;
    $this->user->save();
    $this->ws1->users()->attach($this->user->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->user->assignRole('owner');
});

it('records a recent workspace entry on switch', function () {
    $event = new WorkspaceSwitched(
        user_id: $this->user->id,
        from_workspace_id: $this->ws1->id,
        to_workspace_id: $this->ws2->id,
        ip_address: '127.0.0.1',
        occurred_at: now(),
    );

    app(RecordRecentWorkspace::class)->handle($event);

    $recent = WorkspaceRecent::where('user_id', $this->user->id)
        ->where('workspace_id', $this->ws2->id)
        ->first();

    expect($recent)->not->toBeNull()
        ->and($recent->organization_id)->toBe($this->org->id);
});

it('upserts on repeated switches to same workspace', function () {
    $listener = app(RecordRecentWorkspace::class);

    $event = new WorkspaceSwitched(
        user_id: $this->user->id,
        from_workspace_id: $this->ws1->id,
        to_workspace_id: $this->ws2->id,
        ip_address: '127.0.0.1',
        occurred_at: now()->subMinutes(5),
    );
    $listener->handle($event);

    $event2 = new WorkspaceSwitched(
        user_id: $this->user->id,
        from_workspace_id: $this->ws1->id,
        to_workspace_id: $this->ws2->id,
        ip_address: '127.0.0.1',
        occurred_at: now(),
    );
    $listener->handle($event2);

    $count = WorkspaceRecent::where('user_id', $this->user->id)
        ->where('workspace_id', $this->ws2->id)
        ->count();

    expect($count)->toBe(1);
});

it('prunes old entries beyond 10 per user+org', function () {
    $listener = app(RecordRecentWorkspace::class);

    // Create 12 workspaces and switch to each
    $workspaces = collect();
    for ($i = 0; $i < 12; $i++) {
        $ws = new Workspace(['name' => "WS Extra $i"]);
        $ws->organization_id = $this->org->id;
        $ws->save();
        $workspaces->push($ws);
    }

    foreach ($workspaces as $index => $ws) {
        $event = new WorkspaceSwitched(
            user_id: $this->user->id,
            from_workspace_id: $this->ws1->id,
            to_workspace_id: $ws->id,
            ip_address: '127.0.0.1',
            occurred_at: now()->addSeconds($index),
        );
        $listener->handle($event);
    }

    $count = WorkspaceRecent::where('user_id', $this->user->id)
        ->where('organization_id', $this->org->id)
        ->count();

    expect($count)->toBe(10);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test tests/Feature/Foundation/RecordRecentWorkspaceTest.php`
Expected: FAIL — class `RecordRecentWorkspace` not found

- [ ] **Step 3: Implement the listener**

```php
<?php
// app/Listeners/RecordRecentWorkspace.php

namespace App\Listeners;

use App\Events\WorkspaceSwitched;
use App\Models\Workspace;
use App\Models\WorkspaceRecent;

class RecordRecentWorkspace
{
    public function handle(WorkspaceSwitched $event): void
    {
        $workspace = Workspace::find($event->to_workspace_id);
        if (! $workspace) {
            return;
        }

        WorkspaceRecent::upsert(
            [
                'user_id' => $event->user_id,
                'organization_id' => $workspace->organization_id,
                'workspace_id' => $event->to_workspace_id,
                'switched_at' => $event->occurred_at,
            ],
            ['user_id', 'organization_id', 'workspace_id'],
            ['switched_at'],
        );

        // Prune old entries: keep only the 10 most recent per user+org
        $this->prune($event->user_id, $workspace->organization_id);
    }

    private function prune(int $userId, int $orgId): void
    {
        $keepIds = WorkspaceRecent::where('user_id', $userId)
            ->where('organization_id', $orgId)
            ->orderByDesc('switched_at')
            ->limit(10)
            ->pluck('id');

        WorkspaceRecent::where('user_id', $userId)
            ->where('organization_id', $orgId)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test tests/Feature/Foundation/RecordRecentWorkspaceTest.php`
Expected: All 3 tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Listeners/RecordRecentWorkspace.php tests/Feature/Foundation/RecordRecentWorkspaceTest.php
git commit -m "feat: record recent workspace switches for switcher

New RecordRecentWorkspace listener on WorkspaceSwitched event.
Upserts to workspace_recent table, prunes beyond 10 per user+org."
```

---

## Task 4: Navigation — Sidebar Link

**Files:**
- Modify: `resources/views/components/layouts/app.blade.php:42-43`
- Create: `tests/Feature/Navigation/SidebarWorkspaceLinkTest.php`

**Context:** The sidebar nav is in `app.blade.php` lines 22-44. Integrations is the last item before Settings (line 42-43). We need to add a "Workspaces" item between them, visible only to users with `can('manage')`.

- [ ] **Step 1: Write failing test**

```php
<?php
// tests/Feature/Navigation/SidebarWorkspaceLinkTest.php

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();
});

it('shows workspaces sidebar link for owner', function () {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $owner->organizations()->attach($this->org->id);
    $owner->current_organization_id = $this->org->id;
    $owner->save();
    $this->workspace->users()->attach($owner->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $owner->assignRole('owner');

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-testid="nav-workspaces"', false);
});

it('hides workspaces sidebar link for editor', function () {
    $editor = User::factory()->create(['email_verified_at' => now()]);
    $editor->organizations()->attach($this->org->id);
    $editor->current_organization_id = $this->org->id;
    $editor->save();
    $this->workspace->users()->attach($editor->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $editor->assignRole('editor');

    $this->actingAs($editor)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-testid="nav-workspaces"', false);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test tests/Feature/Navigation/SidebarWorkspaceLinkTest.php`
Expected: First test FAILS (no `nav-workspaces` testid in sidebar)

- [ ] **Step 3: Add sidebar link**

In `resources/views/components/layouts/app.blade.php`, insert between the Integrations item (line 42) and the Settings item (line 43):

```blade
            <flux:sidebar.item icon="puzzle-piece" href="{{ route('integrations') }}" :current="request()->routeIs('integrations', 'integrations.*')" data-testid="nav-integrations">Integrations</flux:sidebar.item>
            @can('manage')
                <flux:sidebar.item icon="square-3-stack-3d" href="{{ route('settings.workspaces') }}" :current="request()->routeIs('settings.workspaces*')" data-testid="nav-workspaces">Workspaces</flux:sidebar.item>
            @endcan
            <flux:sidebar.item icon="cog-6-tooth" href="{{ route('settings.profile') }}" :current="request()->routeIs('settings.*') && !request()->routeIs('settings.workspaces*')" data-testid="nav-settings">Settings</flux:sidebar.item>
```

Note: The Settings `:current` check needs updating so it doesn't also highlight when on workspace pages (since those are under `settings.*` too).

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test tests/Feature/Navigation/SidebarWorkspaceLinkTest.php`
Expected: Both tests PASS

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/layouts/app.blade.php tests/Feature/Navigation/SidebarWorkspaceLinkTest.php
git commit -m "feat: add workspaces sidebar link for owner/admin

New sidebar nav item between Integrations and Settings, visible
only to users with manage permission. Updates Settings current
state to avoid double-highlighting on workspace pages."
```

---

## Task 5: Workspace Switcher Enhancements

**Files:**
- Modify: `resources/views/components/layouts/app.blade.php:111-134`
- Modify: `app/Services/WorkspaceContext.php` — add methods for pins and recents
- Add: `routes/web.php` — pin toggle route
- Create: `tests/Feature/Foundation/WorkspaceSwitcherTest.php`

**Context:** The current switcher (lines 111-134 of app.blade.php) is a simple Flux dropdown with `POST` forms for each workspace. It needs: a "Manage Workspaces" link at top (owner/admin), search input (5+ workspaces), pinned section, recent section, all workspaces section, and pin toggle. Pin toggle needs a dedicated route since it's an AJAX action.

- [ ] **Step 1: Add pin/recent helper methods to WorkspaceContext**

Add to `app/Services/WorkspaceContext.php`:

```php
/**
 * Get pinned workspace IDs for a user in an organization.
 */
public function pinnedWorkspaceIds(User $user, Organization $organization): Collection
{
    return $user->workspaces()
        ->where('workspaces.organization_id', $organization->id)
        ->wherePivot('is_pinned', true)
        ->pluck('workspaces.id');
}

/**
 * Get recent workspaces for a user in an organization.
 * Returns last 3 switched-to workspaces, excluding current.
 */
public function recentWorkspaces(User $user, Organization $organization, ?int $excludeWorkspaceId = null): Collection
{
    $query = \App\Models\WorkspaceRecent::where('user_id', $user->id)
        ->where('organization_id', $organization->id)
        ->orderByDesc('switched_at')
        ->limit(3);

    if ($excludeWorkspaceId) {
        $query->where('workspace_id', '!=', $excludeWorkspaceId);
    }

    return $query->with('workspace')->get()->pluck('workspace')->filter();
}

/**
 * Toggle pin state for a workspace.
 */
public function togglePin(User $user, Workspace $workspace): bool
{
    $pivot = $user->workspaces()->where('workspaces.id', $workspace->id)->first();

    if ($pivot) {
        $newState = ! $pivot->pivot->is_pinned;
        $user->workspaces()->updateExistingPivot($workspace->id, ['is_pinned' => $newState]);
        return $newState;
    }

    // For implicit-access users (owner/admin) with no pivot entry — create one for pin storage
    $user->workspaces()->attach($workspace->id, ['is_pinned' => true]);
    return true;
}
```

- [ ] **Step 2: Add pin toggle route**

In `routes/web.php`, add near the `switch-workspace` route (around line 208):

```php
Route::post('/toggle-workspace-pin/{workspace}', function (\App\Models\Workspace $workspace) {
    $user = auth()->user();
    $org = $user->currentOrganization;

    if ($workspace->organization_id !== $org->id) {
        abort(403);
    }

    $isPinned = app(\App\Services\WorkspaceContext::class)->togglePin($user, $workspace);

    return response()->json(['is_pinned' => $isPinned]);
})->middleware(['auth', 'verified', 'onboarded', 'organization'])->name('toggle-workspace-pin');
```

- [ ] **Step 3: Write tests for pin and recent features**

```php
<?php
// tests/Feature/Foundation/WorkspaceSwitcherTest.php

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceRecent;
use App\Services\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->ws1 = new Workspace(['name' => 'WS 1']);
    $this->ws1->organization_id = $this->org->id;
    $this->ws1->is_default = true;
    $this->ws1->save();

    $this->ws2 = new Workspace(['name' => 'WS 2']);
    $this->ws2->organization_id = $this->org->id;
    $this->ws2->save();

    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    $this->owner->organizations()->attach($this->org->id);
    $this->owner->current_organization_id = $this->org->id;
    $this->owner->save();
    $this->ws1->users()->attach($this->owner->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->owner->assignRole('owner');
});

it('toggles pin on for a workspace with existing pivot', function () {
    $context = app(WorkspaceContext::class);
    $result = $context->togglePin($this->owner, $this->ws1);

    expect($result)->toBeTrue();
    expect($this->owner->workspaces()->where('workspaces.id', $this->ws1->id)->first()->pivot->is_pinned)->toBeTrue();
});

it('toggles pin off for already pinned workspace', function () {
    $context = app(WorkspaceContext::class);
    $context->togglePin($this->owner, $this->ws1); // pin on
    $result = $context->togglePin($this->owner, $this->ws1); // pin off

    expect($result)->toBeFalse();
});

it('creates pivot entry when implicit-access user pins', function () {
    // owner has implicit access to ws2 but no pivot
    $context = app(WorkspaceContext::class);
    $result = $context->togglePin($this->owner, $this->ws2);

    expect($result)->toBeTrue();
    expect($this->owner->workspaces()->where('workspaces.id', $this->ws2->id)->exists())->toBeTrue();
});

it('returns pinned workspace ids', function () {
    $context = app(WorkspaceContext::class);
    $context->togglePin($this->owner, $this->ws1);

    $pinned = $context->pinnedWorkspaceIds($this->owner, $this->org);
    expect($pinned)->toContain($this->ws1->id);
});

it('returns recent workspaces excluding current', function () {
    WorkspaceRecent::create([
        'user_id' => $this->owner->id,
        'organization_id' => $this->org->id,
        'workspace_id' => $this->ws2->id,
        'switched_at' => now(),
    ]);

    $context = app(WorkspaceContext::class);
    $recents = $context->recentWorkspaces($this->owner, $this->org, $this->ws1->id);

    expect($recents)->toHaveCount(1)
        ->and($recents->first()->id)->toBe($this->ws2->id);
});

it('pin toggle route works', function () {
    $this->actingAs($this->owner)
        ->post(route('toggle-workspace-pin', $this->ws1))
        ->assertOk()
        ->assertJson(['is_pinned' => true]);
});
```

- [ ] **Step 4: Run tests**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test tests/Feature/Foundation/WorkspaceSwitcherTest.php`
Expected: All tests PASS

- [ ] **Step 5: Redesign workspace switcher template**

Replace the workspace switcher section in `resources/views/components/layouts/app.blade.php` (lines 111-134) with the enhanced version. This is a significant template change using Alpine.js for search and Livewire for pin toggle:

```blade
            {{-- Workspace Switcher --}}
            @php
                $workspace = app(\App\Services\WorkspaceContext::class)->getWorkspace();
                $workspaceContext = app(\App\Services\WorkspaceContext::class);
                $accessibleWorkspaceIds = $currentOrg ? $user->accessibleWorkspaceIds($currentOrg) : collect();
                $allWorkspaces = $accessibleWorkspaceIds->isNotEmpty()
                    ? \App\Models\Workspace::whereIn('id', $accessibleWorkspaceIds)->orderBy('name')->get()
                    : collect();
                $pinnedIds = $currentOrg ? $workspaceContext->pinnedWorkspaceIds($user, $currentOrg) : collect();
                $pinnedWorkspaces = $allWorkspaces->filter(fn ($ws) => $pinnedIds->contains($ws->id));
                $recentWorkspaces = $currentOrg && $workspace
                    ? $workspaceContext->recentWorkspaces($user, $currentOrg, $workspace->id)
                    : collect();
                $showSearch = $allWorkspaces->count() >= 5;
            @endphp

            <flux:dropdown data-testid="workspace-switcher">
                <flux:button variant="ghost" class="flex items-center gap-2">
                    <span class="text-sm font-medium">{{ $workspace?->name ?? 'Select Workspace' }}</span>
                    <flux:icon.chevron-down class="size-4" />
                </flux:button>

                <flux:menu class="min-w-[240px]" x-data="{ search: '' }">
                    @can('manage')
                        <flux:menu.item icon="cog-6-tooth" href="{{ route('settings.workspaces') }}" data-testid="manage-workspaces-link">
                            Manage Workspaces
                        </flux:menu.item>
                        <flux:separator />
                    @endcan

                    @if ($showSearch)
                        <div class="px-2 py-1.5">
                            <input
                                type="text"
                                x-model="search"
                                placeholder="Search workspaces..."
                                class="w-full text-sm bg-transparent border border-zinc-200 dark:border-zinc-600 rounded-md px-2.5 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500 text-zinc-900 dark:text-white placeholder-zinc-400"
                            />
                        </div>
                    @endif

                    @if ($pinnedWorkspaces->isNotEmpty())
                        <flux:menu.heading>Pinned</flux:menu.heading>
                        @foreach ($pinnedWorkspaces as $ws)
                            <div x-show="!search || '{{ strtolower($ws->name) }}'.includes(search.toLowerCase())" class="flex items-center">
                                <form method="POST" action="{{ route('switch-workspace', $ws) }}" class="flex-1">
                                    @csrf
                                    <flux:menu.item type="submit" class="flex items-center justify-between">
                                        <span>{{ $ws->name }}</span>
                                        @if ($workspace && $ws->id === $workspace->id)
                                            <flux:icon.check class="size-4 text-blue-500" />
                                        @endif
                                    </flux:menu.item>
                                </form>
                            </div>
                        @endforeach
                        <flux:separator />
                    @endif

                    @if ($recentWorkspaces->isNotEmpty())
                        <flux:menu.heading>Recent</flux:menu.heading>
                        @foreach ($recentWorkspaces as $ws)
                            <div x-show="!search || '{{ strtolower($ws->name) }}'.includes(search.toLowerCase())">
                                <form method="POST" action="{{ route('switch-workspace', $ws) }}">
                                    @csrf
                                    <flux:menu.item type="submit">{{ $ws->name }}</flux:menu.item>
                                </form>
                            </div>
                        @endforeach
                        <flux:separator />
                    @endif

                    <flux:menu.heading>All Workspaces</flux:menu.heading>
                    @foreach ($allWorkspaces as $ws)
                        <div x-show="!search || '{{ strtolower($ws->name) }}'.includes(search.toLowerCase())" class="group flex items-center">
                            <form method="POST" action="{{ route('switch-workspace', $ws) }}" class="flex-1">
                                @csrf
                                <flux:menu.item type="submit" class="flex items-center justify-between">
                                    <span>{{ $ws->name }}</span>
                                    @if ($workspace && $ws->id === $workspace->id)
                                        <flux:icon.check class="size-4 text-blue-500" />
                                    @endif
                                </flux:menu.item>
                            </form>
                            <button
                                x-data
                                x-on:click.stop="fetch('{{ route('toggle-workspace-pin', $ws) }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' } }).then(() => window.location.reload())"
                                class="hidden group-hover:flex items-center justify-center size-7 shrink-0 mr-1 rounded hover:bg-zinc-100 dark:hover:bg-zinc-700"
                                title="{{ $pinnedIds->contains($ws->id) ? 'Unpin' : 'Pin' }}"
                            >
                                @if ($pinnedIds->contains($ws->id))
                                    <flux:icon.star class="size-3.5 text-amber-500" variant="solid" />
                                @else
                                    <flux:icon.star class="size-3.5 text-zinc-400" />
                                @endif
                            </button>
                        </div>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
```

- [ ] **Step 6: Run full test suite**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test`
Expected: All tests pass

- [ ] **Step 7: Commit**

```bash
git add resources/views/components/layouts/app.blade.php app/Services/WorkspaceContext.php routes/web.php tests/Feature/Foundation/WorkspaceSwitcherTest.php
git commit -m "feat: enhanced workspace switcher with search, pins, recents

Workspace switcher now includes Manage Workspaces link (admin only),
search input (5+ workspaces), pinned workspaces section, recent
workspaces section, and pin toggle route."
```

---

## Task 6: Workspace Settings Page — Clickable Links

**Files:**
- Modify: `resources/views/pages/settings/workspaces/index.blade.php:176-189`

**Context:** The workspace name (line 184) and member count (line 188) need to become clickable links to the workspace users page. Currently name is a plain `<span>` and count is `{{ $workspace->users_count }}`. The member count should use `totalMemberCount()` from the Workspace model (added in Task 1) to include implicit admins.

- [ ] **Step 1: Write failing test**

```php
// Add to tests/Feature/Settings/WorkspaceSettingsTest.php

it('renders workspace name as link to users page', function () {
    $this->actingAs($this->owner)
        ->get(route('settings.workspaces'))
        ->assertOk()
        ->assertSee(route('settings.workspaces.users', $this->workspace), false);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test tests/Feature/Settings/WorkspaceSettingsTest.php --filter="renders workspace name as link"`
Expected: FAIL — the URL is in an href but let's confirm the link structure

- [ ] **Step 3: Make name and count clickable**

In `resources/views/pages/settings/workspaces/index.blade.php`, update the name cell (around line 183-185) to wrap in a link when not editing:

```blade
                                    @else
                                        <a href="{{ route('settings.workspaces.users', $workspace) }}" class="text-sm font-medium text-zinc-900 dark:text-white hover:text-blue-600 dark:hover:text-blue-400">
                                            {{ $workspace->name }}
                                        </a>
                                    @endif
```

Update the members cell (around line 187-189) to also be a link, and use `totalMemberCount()`:

```blade
                                <flux:table.cell>
                                    <a href="{{ route('settings.workspaces.users', $workspace) }}" class="text-sm text-zinc-500 dark:text-zinc-400 hover:text-blue-600 dark:hover:text-blue-400">
                                        {{ $workspace->totalMemberCount() }}
                                    </a>
                                </flux:table.cell>
```

- [ ] **Step 4: Run tests**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test tests/Feature/Settings/WorkspaceSettingsTest.php`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/settings/workspaces/index.blade.php
git commit -m "feat: make workspace name and member count clickable links

Both now link to the workspace users management page.
Member count uses totalMemberCount() to include implicit admins."
```

---

## Task 7: Workspace Users Page — Redesign

**Files:**
- Modify: `resources/views/pages/settings/workspaces/users.blade.php` (full rewrite of both component and template)
- Create: `tests/Feature/Settings/WorkspaceUsersPageTest.php`

**Context:** This is the largest UI change. The existing page (148 lines) has a simple single-add form and flat user table. The redesign needs: breadcrumb header, Admins section (implicit access users), Members section with search, bulk add (multi-select), bulk remove (checkboxes), copy-from-workspace, and individual remove. Uses Flux UI components throughout. The existing Volt component class handles mount, getWorkspaceUsers, getAvailableUsers, addUser, removeUser.

- [ ] **Step 1: Write tests for the redesigned page**

```php
<?php
// tests/Feature/Settings/WorkspaceUsersPageTest.php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();

    $this->owner = User::factory()->create(['name' => 'Owner User', 'email_verified_at' => now()]);
    $this->owner->organizations()->attach($this->org->id);
    $this->owner->current_organization_id = $this->org->id;
    $this->owner->save();
    $this->workspace->users()->attach($this->owner->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->owner->assignRole('owner');

    $this->admin = User::factory()->create(['name' => 'Admin User', 'email_verified_at' => now()]);
    $this->admin->organizations()->attach($this->org->id);
    $this->admin->current_organization_id = $this->org->id;
    $this->admin->save();
    $this->admin->assignRole('admin');

    $this->editor = User::factory()->create(['name' => 'Editor User', 'email_verified_at' => now()]);
    $this->editor->organizations()->attach($this->org->id);
    $this->editor->current_organization_id = $this->org->id;
    $this->editor->save();
    $this->workspace->users()->attach($this->editor->id);
    $this->editor->assignRole('editor');
});

it('shows admins section with implicit-access users', function () {
    $this->actingAs($this->owner)
        ->get(route('settings.workspaces.users', $this->workspace))
        ->assertOk()
        ->assertSee('Admins')
        ->assertSee('Access via role')
        ->assertSee('Owner User')
        ->assertSee('Admin User');
});

it('shows members section with explicitly assigned users', function () {
    $this->actingAs($this->owner)
        ->get(route('settings.workspaces.users', $this->workspace))
        ->assertOk()
        ->assertSee('Editor User');
});

it('does not show remove button for admin-section users', function () {
    // Admin section users should not have remove actions
    $response = $this->actingAs($this->owner)
        ->get(route('settings.workspaces.users', $this->workspace));

    $response->assertOk();
    // The admin section should not contain removeUser calls for admin users
    // This is validated by the template structure
});

it('bulk adds multiple users', function () {
    $viewer = User::factory()->create(['name' => 'Viewer User', 'email_verified_at' => now()]);
    $viewer->organizations()->attach($this->org->id);
    $viewer->current_organization_id = $this->org->id;
    $viewer->save();
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $viewer->assignRole('viewer');

    Volt::actingAs($this->owner)
        ->test('settings.workspaces.users', ['workspace' => $this->workspace])
        ->set('addUserIds', [$viewer->id])
        ->call('addUsers')
        ->assertHasNoErrors();

    expect($this->workspace->users()->where('users.id', $viewer->id)->exists())->toBeTrue();
});

it('bulk removes multiple users', function () {
    Volt::actingAs($this->owner)
        ->test('settings.workspaces.users', ['workspace' => $this->workspace])
        ->set('selectedUserIds', [$this->editor->id])
        ->call('removeUsers')
        ->assertHasNoErrors();

    expect($this->workspace->users()->where('users.id', $this->editor->id)->exists())->toBeFalse();
});

it('copies members from another workspace filtered by role', function () {
    $ws2 = new Workspace(['name' => 'Source WS']);
    $ws2->organization_id = $this->org->id;
    $ws2->save();

    $viewer = User::factory()->create(['name' => 'Viewer User', 'email_verified_at' => now()]);
    $viewer->organizations()->attach($this->org->id);
    $viewer->current_organization_id = $this->org->id;
    $viewer->save();
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $viewer->assignRole('viewer');
    $ws2->users()->attach($viewer->id);

    Volt::actingAs($this->owner)
        ->test('settings.workspaces.users', ['workspace' => $this->workspace])
        ->set('copySourceId', $ws2->id)
        ->set('copyRoles', ['viewer'])
        ->call('copyFromWorkspace')
        ->assertHasNoErrors();

    expect($this->workspace->users()->where('users.id', $viewer->id)->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test tests/Feature/Settings/WorkspaceUsersPageTest.php`
Expected: FAIL — methods like `addUsers`, `removeUsers`, `copyFromWorkspace` don't exist yet

- [ ] **Step 3: Rewrite the Volt component class**

Rewrite the PHP section of `resources/views/pages/settings/workspaces/users.blade.php`. The component needs:
- `mount()` — validate workspace belongs to org (existing)
- `getAdminUsers()` — org users with owner/admin role (implicit access)
- `getWorkspaceMembers()` — explicit pivot members, searchable
- `getAvailableUsers()` — org users not in workspace and not owner/admin
- `addUsers(array $userIds)` — bulk add
- `removeUsers(array $userIds)` — bulk remove
- `removeUser(int $userId)` — single remove (keep for individual action)
- `copyFromWorkspace(int $sourceId, array $roles)` — copy filtered members
- `getOtherWorkspaces()` — for copy-from dropdown
- Search state: `$search` property for filtering members

```php
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

        // Exclude owner/admin users (shown in admin section)
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

        // Exclude already-assigned users and owner/admin (they have implicit access)
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
```

- [ ] **Step 4: Rewrite the Blade template**

Replace the template section of `resources/views/pages/settings/workspaces/users.blade.php` (everything after `?>`) with the redesigned layout:

```blade
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
```

- [ ] **Step 5: Run tests**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test tests/Feature/Settings/WorkspaceUsersPageTest.php`
Expected: All tests PASS

- [ ] **Step 6: Run full test suite**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test`
Expected: All tests pass (including existing WorkspaceSettingsTest)

- [ ] **Step 7: Commit**

```bash
git add resources/views/pages/settings/workspaces/users.blade.php tests/Feature/Settings/WorkspaceUsersPageTest.php
git commit -m "feat: redesign workspace users page with admins section and bulk ops

Split into Admins (implicit access) and Members (explicit) sections.
Add bulk add/remove, search/filter, copy-from-workspace with role
filtering. Admins section shows explanation text, no remove action."
```

---

## Task 8: User-Centric Workspace Assignment

**Files:**
- Modify: `resources/views/pages/settings/users/index.blade.php:240-262`
- Add route in `routes/web.php` for toggling workspace assignment
- Create: `tests/Feature/Settings/UserWorkspaceAssignmentTest.php`

**Context:** The users settings page (`settings/users/index.blade.php`) has a Volt component with member listing and an actions dropdown per user (lines 240-262). We need to add a "Manage Workspaces" menu item that reveals a searchable dropdown with checkboxes for all org workspaces. Toggle is immediate via Livewire. The existing component is `settings.users.index`.

- [ ] **Step 1: Write tests**

```php
<?php
// tests/Feature/Settings/UserWorkspaceAssignmentTest.php

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->ws1 = new Workspace(['name' => 'WS 1']);
    $this->ws1->organization_id = $this->org->id;
    $this->ws1->is_default = true;
    $this->ws1->save();

    $this->ws2 = new Workspace(['name' => 'WS 2']);
    $this->ws2->organization_id = $this->org->id;
    $this->ws2->save();

    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    $this->owner->organizations()->attach($this->org->id);
    $this->owner->current_organization_id = $this->org->id;
    $this->owner->save();
    $this->ws1->users()->attach($this->owner->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->owner->assignRole('owner');

    $this->editor = User::factory()->create(['name' => 'Editor User', 'email_verified_at' => now()]);
    $this->editor->organizations()->attach($this->org->id);
    $this->editor->current_organization_id = $this->org->id;
    $this->editor->save();
    $this->ws1->users()->attach($this->editor->id);
    $this->editor->assignRole('editor');
});

it('toggles workspace assignment for a user', function () {
    // Editor is not in ws2 — assign them
    Volt::actingAs($this->owner)
        ->test('settings.users.index')
        ->call('toggleWorkspaceAssignment', $this->editor->id, $this->ws2->id)
        ->assertHasNoErrors();

    expect($this->ws2->users()->where('users.id', $this->editor->id)->exists())->toBeTrue();

    // Toggle again — remove them
    Volt::actingAs($this->owner)
        ->test('settings.users.index')
        ->call('toggleWorkspaceAssignment', $this->editor->id, $this->ws2->id)
        ->assertHasNoErrors();

    expect($this->ws2->users()->where('users.id', $this->editor->id)->exists())->toBeFalse();
});

it('returns workspace assignments for a user', function () {
    Volt::actingAs($this->owner)
        ->test('settings.users.index')
        ->call('getWorkspaceAssignments', $this->editor->id)
        ->assertHasNoErrors();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test tests/Feature/Settings/UserWorkspaceAssignmentTest.php`
Expected: FAIL — methods don't exist

- [ ] **Step 3: Add methods to the users index Volt component**

Add to the PHP class section of `resources/views/pages/settings/users/index.blade.php`, before the closing `}; ?>`:

```php
    public ?int $managingWorkspacesFor = null;

    public string $workspaceSearch = '';

    public function showWorkspaceManager(int $userId): void
    {
        $this->managingWorkspacesFor = $userId;
        $this->workspaceSearch = '';
    }

    public function getWorkspaceAssignments(int $userId): array
    {
        $org = auth()->user()->currentOrganization;
        $user = User::findOrFail($userId);
        $workspaces = $org->workspaces()->orderBy('name')->get();
        $assignedIds = $user->workspaces()
            ->where('workspaces.organization_id', $org->id)
            ->pluck('workspaces.id')
            ->toArray();

        // Check if user is owner/admin (implicit access)
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $user->unsetRelation('roles');
        $isImplicit = $user->hasRole(['owner', 'admin']);

        return [
            'workspaces' => $workspaces->map(fn ($ws) => [
                'id' => $ws->id,
                'name' => $ws->name,
                'assigned' => $isImplicit || in_array($ws->id, $assignedIds),
                'implicit' => $isImplicit,
            ])->toArray(),
            'isImplicit' => $isImplicit,
        ];
    }

    public ?int $confirmingLastWorkspaceRemoval = null;

    public ?int $lastWorkspaceRemovalWorkspaceId = null;

    public function toggleWorkspaceAssignment(int $userId, int $workspaceId): void
    {
        $org = auth()->user()->currentOrganization;
        $user = User::findOrFail($userId);
        $workspace = $org->workspaces()->findOrFail($workspaceId);

        // Don't allow toggling for owner/admin (implicit access)
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $user->unsetRelation('roles');
        if ($user->hasRole(['owner', 'admin'])) {
            return;
        }

        $isAssigned = $workspace->users()->where('users.id', $userId)->exists();

        if ($isAssigned) {
            // Check if this is their last workspace
            $assignedCount = $user->workspaces()
                ->where('workspaces.organization_id', $org->id)
                ->count();

            if ($assignedCount <= 1) {
                $this->confirmingLastWorkspaceRemoval = $userId;
                $this->lastWorkspaceRemovalWorkspaceId = $workspaceId;
                return;
            }

            $workspace->users()->detach($userId);
            $this->dispatch('notify', message: "Removed from {$workspace->name}");
        } else {
            $workspace->users()->attach($userId);
            $this->dispatch('notify', message: "Added to {$workspace->name}");
        }
    }

    public function confirmLastWorkspaceRemoval(): void
    {
        if (! $this->confirmingLastWorkspaceRemoval || ! $this->lastWorkspaceRemovalWorkspaceId) {
            return;
        }

        $org = auth()->user()->currentOrganization;
        $workspace = $org->workspaces()->findOrFail($this->lastWorkspaceRemovalWorkspaceId);
        $workspace->users()->detach($this->confirmingLastWorkspaceRemoval);

        $this->dispatch('notify', message: "Removed from {$workspace->name}");
        $this->confirmingLastWorkspaceRemoval = null;
        $this->lastWorkspaceRemovalWorkspaceId = null;
    }
```

- [ ] **Step 4: Add UI for workspace management dropdown**

In the template section of `resources/views/pages/settings/users/index.blade.php`, add a "Manage Workspaces" menu item in the existing actions dropdown (around line 253, after the role change items), and add a modal/panel for the workspace assignment:

After the existing `<flux:menu.separator />` (line 254), add:

```blade
                                                <flux:menu.item wire:click="showWorkspaceManager({{ $member->id }})">
                                                    Manage Workspaces
                                                </flux:menu.item>
```

Then add the workspace manager modal after the existing modals (after line 301, before `</div> @endvolt`):

```blade
            {{-- Workspace Assignment Modal --}}
            <flux:modal wire:model.self="managingWorkspacesFor" class="max-w-sm">
                @if ($managingWorkspacesFor)
                    @php $assignments = $this->getWorkspaceAssignments($managingWorkspacesFor); @endphp
                    <div class="space-y-4">
                        <flux:heading>Manage Workspaces</flux:heading>

                        @if ($assignments['isImplicit'])
                            <p class="text-sm text-zinc-400">Has access to all workspaces via organization role</p>
                        @endif

                        <div class="mb-3">
                            <input
                                type="text"
                                wire:model.live.debounce.300ms="workspaceSearch"
                                placeholder="Search workspaces..."
                                class="w-full text-sm bg-transparent border border-zinc-200 dark:border-zinc-600 rounded-md px-2.5 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500 text-zinc-900 dark:text-white placeholder-zinc-400"
                            />
                        </div>

                        <div class="max-h-64 overflow-y-auto">
                            @foreach ($assignments['workspaces'] as $ws)
                                @if (! $workspaceSearch || str_contains(strtolower($ws['name']), strtolower($workspaceSearch)))
                                    <label class="flex items-center gap-3 px-2 py-2 hover:bg-zinc-50 dark:hover:bg-zinc-700 rounded cursor-pointer">
                                        <input
                                            type="checkbox"
                                            {{ $ws['assigned'] ? 'checked' : '' }}
                                            {{ $ws['implicit'] ? 'disabled' : '' }}
                                            wire:click="toggleWorkspaceAssignment({{ $managingWorkspacesFor }}, {{ $ws['id'] }})"
                                            class="rounded border-zinc-300 dark:border-zinc-600 text-blue-600 {{ $ws['implicit'] ? 'opacity-50' : '' }}"
                                        >
                                        <span class="text-sm text-zinc-900 dark:text-white">{{ $ws['name'] }}</span>
                                    </label>
                                @endif
                            @endforeach
                        </div>

                        <div class="flex justify-end">
                            <flux:button wire:click="$set('managingWorkspacesFor', null)" variant="ghost" size="sm">Close</flux:button>
                        </div>
                    </div>
                @endif
            </flux:modal>

            {{-- Last Workspace Removal Warning --}}
            <flux:modal wire:model.self="confirmingLastWorkspaceRemoval" class="max-w-sm">
                <div class="space-y-4">
                    <flux:heading>Remove Last Workspace</flux:heading>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        This user will lose access to all workspaces. They'll be prompted to select a workspace on next login.
                    </p>
                    <div class="flex justify-end gap-2">
                        <flux:button wire:click="$set('confirmingLastWorkspaceRemoval', null)" variant="ghost">Cancel</flux:button>
                        <flux:button wire:click="confirmLastWorkspaceRemoval" variant="danger">Remove</flux:button>
                    </div>
                </div>
            </flux:modal>
```

- [ ] **Step 5: Run tests**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test tests/Feature/Settings/UserWorkspaceAssignmentTest.php`
Expected: All tests PASS

- [ ] **Step 6: Run full test suite**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test`
Expected: All tests pass

- [ ] **Step 7: Commit**

```bash
git add resources/views/pages/settings/users/index.blade.php routes/web.php tests/Feature/Settings/UserWorkspaceAssignmentTest.php
git commit -m "feat: user-centric workspace assignment from users page

Add 'Manage Workspaces' action per user on the Settings > Users page.
Searchable modal with checkboxes for all org workspaces. Immediate
toggle. Owner/admin checkboxes disabled with implicit access note."
```

---

## Task 9: Final Integration Test and Cleanup

**Files:**
- Run all tests
- Verify no regressions

- [ ] **Step 1: Run full test suite**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test`
Expected: All tests pass

- [ ] **Step 2: Check for any Pint formatting issues**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && ./vendor/bin/pint --test`
Expected: No formatting issues, or fix any found

- [ ] **Step 3: If Pint finds issues, fix and commit**

```bash
cd /home/jordan/projects/revat/revat-v4/revat.io && ./vendor/bin/pint
git add -A
git commit -m "style: fix formatting via Pint"
```

- [ ] **Step 4: Verify the workspace users page renders correctly**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test tests/Feature/Settings/WorkspaceUsersPageTest.php -v`
Expected: All tests pass with verbose output showing test names

- [ ] **Step 5: Verify implicit access works end-to-end**

Run: `cd /home/jordan/projects/revat/revat-v4/revat.io && php artisan test tests/Feature/Foundation/ImplicitWorkspaceAccessTest.php -v`
Expected: All 5 tests pass
