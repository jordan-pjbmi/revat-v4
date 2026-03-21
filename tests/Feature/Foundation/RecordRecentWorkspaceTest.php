<?php

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
        user_id: $this->user->id, from_workspace_id: $this->ws1->id,
        to_workspace_id: $this->ws2->id, ip_address: '127.0.0.1', occurred_at: now(),
    );
    app(RecordRecentWorkspace::class)->handle($event);
    $recent = WorkspaceRecent::where('user_id', $this->user->id)->where('workspace_id', $this->ws2->id)->first();
    expect($recent)->not->toBeNull()->and($recent->organization_id)->toBe($this->org->id);
});

it('upserts on repeated switches to same workspace', function () {
    $listener = app(RecordRecentWorkspace::class);
    $event = new WorkspaceSwitched(user_id: $this->user->id, from_workspace_id: $this->ws1->id, to_workspace_id: $this->ws2->id, ip_address: '127.0.0.1', occurred_at: now()->subMinutes(5));
    $listener->handle($event);
    $event2 = new WorkspaceSwitched(user_id: $this->user->id, from_workspace_id: $this->ws1->id, to_workspace_id: $this->ws2->id, ip_address: '127.0.0.1', occurred_at: now());
    $listener->handle($event2);
    $count = WorkspaceRecent::where('user_id', $this->user->id)->where('workspace_id', $this->ws2->id)->count();
    expect($count)->toBe(1);
});

it('prunes old entries beyond 10 per user+org', function () {
    $listener = app(RecordRecentWorkspace::class);
    $workspaces = collect();
    for ($i = 0; $i < 12; $i++) {
        $ws = new Workspace(['name' => "WS Extra $i"]);
        $ws->organization_id = $this->org->id;
        $ws->save();
        $workspaces->push($ws);
    }
    foreach ($workspaces as $index => $ws) {
        $event = new WorkspaceSwitched(user_id: $this->user->id, from_workspace_id: $this->ws1->id, to_workspace_id: $ws->id, ip_address: '127.0.0.1', occurred_at: now()->addSeconds($index));
        $listener->handle($event);
    }
    $count = WorkspaceRecent::where('user_id', $this->user->id)->where('organization_id', $this->org->id)->count();
    expect($count)->toBe(10);
});
