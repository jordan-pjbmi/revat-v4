<?php

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();

    $this->org2 = Organization::create(['name' => 'Second Org']);
    $this->workspace2 = new Workspace(['name' => 'Second WS']);
    $this->workspace2->organization_id = $this->org2->id;
    $this->workspace2->is_default = true;
    $this->workspace2->save();

    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->user->organizations()->attach($this->org->id);
    $this->user->organizations()->attach($this->org2->id);
    $this->user->current_organization_id = $this->org->id;
    $this->user->save();
    $this->workspace->users()->attach($this->user->id);
    $this->workspace2->users()->attach($this->user->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->user->assignRole('owner');
});

it('switches organization and updates current_organization_id and workspace context', function () {
    $response = $this->actingAs($this->user)
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->post(route('switch-organization', $this->org2));

    $response->assertRedirect(route('dashboard'));

    $this->user->refresh();
    expect($this->user->current_organization_id)->toBe($this->org2->id);

    // Workspace context should be resolved for the new org
    $workspaceContext = app(WorkspaceContext::class);
    $resolvedWorkspace = $workspaceContext->getWorkspace();
    expect($resolvedWorkspace)->not->toBeNull();
    expect($resolvedWorkspace->organization_id)->toBe($this->org2->id);
});

it('returns 403 when switching to an organization the user does not belong to', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);

    $response = $this->actingAs($this->user)
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->post(route('switch-organization', $otherOrg));

    $response->assertForbidden();
});

it('switches workspace and updates session context', function () {
    // Create a second workspace in the same org
    $ws2 = new Workspace(['name' => 'Second Workspace']);
    $ws2->organization_id = $this->org->id;
    $ws2->save();
    $ws2->users()->attach($this->user->id);

    // Set initial workspace context
    $this->actingAs($this->user);
    app(WorkspaceContext::class)->setWorkspace($this->workspace);

    $response = $this->actingAs($this->user)
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->post(route('switch-workspace', $ws2));

    $response->assertRedirect();

    $workspaceContext = app(WorkspaceContext::class);
    $active = $workspaceContext->getWorkspace();
    expect($active)->not->toBeNull();
    expect($active->id)->toBe($ws2->id);
});

it('returns 403 when switching to a workspace the user does not have access to', function () {
    $restrictedWs = new Workspace(['name' => 'Restricted']);
    $restrictedWs->organization_id = $this->org->id;
    $restrictedWs->save();
    // Not attached to user

    $response = $this->actingAs($this->user)
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->post(route('switch-workspace', $restrictedWs));

    $response->assertForbidden();
});

it('returns 403 when switching to a workspace in a different organization', function () {
    // workspace2 belongs to org2, but user's current org is org
    $response = $this->actingAs($this->user)
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->post(route('switch-workspace', $this->workspace2));

    $response->assertForbidden();
});

it('returns 403 when switching organization during impersonation', function () {
    $this->user->impersonating = true;

    $response = $this->actingAs($this->user)
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->post(route('switch-organization', $this->org2));

    $response->assertForbidden();
});

it('has throttle middleware on switch-organization route', function () {
    $route = app('router')->getRoutes()->getByName('switch-organization');
    $middleware = $route->gatherMiddleware();

    expect($middleware)->toContain('throttle:30,1');
});

it('has throttle middleware on switch-workspace route', function () {
    $route = app('router')->getRoutes()->getByName('switch-workspace');
    $middleware = $route->gatherMiddleware();

    expect($middleware)->toContain('throttle:30,1');
});
