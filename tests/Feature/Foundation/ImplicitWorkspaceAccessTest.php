<?php

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

    // ws2 is intentionally NOT attached to any user in the pivot — implicit access only
    $this->ws2 = new Workspace(['name' => 'Workspace 2']);
    $this->ws2->organization_id = $this->org->id;
    $this->ws2->save();

    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    $this->owner->organizations()->attach($this->org->id);
    $this->owner->current_organization_id = $this->org->id;
    $this->owner->save();
    $this->ws1->users()->attach($this->owner->id); // Only attach to ws1, NOT ws2

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->owner->assignRole('owner');

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->organizations()->attach($this->org->id);
    $this->admin->current_organization_id = $this->org->id;
    $this->admin->save();
    $this->ws1->users()->attach($this->admin->id); // Only attach to ws1, NOT ws2
    $this->admin->assignRole('admin');

    $this->editor = User::factory()->create(['email_verified_at' => now()]);
    $this->editor->organizations()->attach($this->org->id);
    $this->editor->current_organization_id = $this->org->id;
    $this->editor->save();
    $this->ws1->users()->attach($this->editor->id); // Only attach to ws1, NOT ws2
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
    $context = app(WorkspaceContext::class);
    $ids = $context->accessibleWorkspaceIds($this->admin, $this->org);
    expect($ids)->toContain($this->ws2->id);

    $this->admin->syncRoles(['editor']);
    $context->reset();

    $ids = $context->accessibleWorkspaceIds($this->admin, $this->org);
    expect($ids)->not->toContain($this->ws2->id)
        ->and($ids)->toContain($this->ws1->id);
});

it('user model delegates to workspace context for implicit access', function () {
    $ids = $this->owner->accessibleWorkspaceIds($this->org);

    expect($ids)->toContain($this->ws1->id)
        ->and($ids)->toContain($this->ws2->id);
});

it('totalMemberCount includes implicit admin/owner users not in the pivot', function () {
    // ws2 has no explicit pivot members; owner and admin have implicit access
    $count = $this->ws2->totalMemberCount();

    // 0 explicit + 2 implicit (owner + admin)
    expect($count)->toBe(2);
});

it('totalMemberCount does not double-count admins already in the pivot', function () {
    // ws1 has owner, admin, editor explicitly in pivot
    // owner and admin also have implicit access — totalMemberCount must not double-count them
    $count = $this->ws1->totalMemberCount();

    // 3 explicit (owner + admin + editor) + 0 implicit (all admins already in pivot)
    expect($count)->toBe(3);
});
