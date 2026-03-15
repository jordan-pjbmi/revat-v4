<?php

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

    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->user->organizations()->attach($this->org->id);
    $this->user->current_organization_id = $this->org->id;
    $this->user->save();

    $this->workspace->users()->attach($this->user->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->user->assignRole('owner');
});

it('renders attribution index page', function () {
    $this->actingAs($this->user)
        ->get(route('attribution.stats'))
        ->assertOk()
        ->assertSee('Attribution')
        ->assertSee('Attributed Conversions');
});

it('renders attribution clicks page', function () {
    $this->actingAs($this->user)
        ->get(route('attribution.clicks'))
        ->assertOk()
        ->assertSee('Attribution Clicks');
});

it('renders attribution conversions page', function () {
    $this->actingAs($this->user)
        ->get(route('attribution.conversion-sales'))
        ->assertOk()
        ->assertSee('Attribution Conversions');
});

it('attribution index shows empty state when no data', function () {
    $this->actingAs($this->user)
        ->get(route('attribution.stats'))
        ->assertOk()
        ->assertSee('No attribution data available');
});

it('enforces permission gate on attribution pages', function () {
    $viewer = User::factory()->create(['email_verified_at' => now()]);
    $viewer->organizations()->attach($this->org->id);
    $viewer->current_organization_id = $this->org->id;
    $viewer->save();

    $this->workspace->users()->attach($viewer->id);

    $this->actingAs($viewer)
        ->get(route('attribution.stats'))
        ->assertForbidden();

    $this->actingAs($viewer)
        ->get(route('attribution.clicks'))
        ->assertForbidden();

    $this->actingAs($viewer)
        ->get(route('attribution.conversion-sales'))
        ->assertForbidden();
});
