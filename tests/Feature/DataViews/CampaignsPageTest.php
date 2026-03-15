<?php

use App\Models\CampaignEmail;
use App\Models\Integration;
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

    $this->integration = new Integration([
        'name' => 'Test',
        'platform' => 'activecampaign',
        'data_types' => ['campaign_emails'],
        'is_active' => true,
        'sync_interval_minutes' => 60,
    ]);
    $this->integration->workspace_id = $this->workspace->id;
    $this->integration->organization_id = $this->org->id;
    $this->integration->save();

    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->user->organizations()->attach($this->org->id);
    $this->user->current_organization_id = $this->org->id;
    $this->user->save();

    $this->workspace->users()->attach($this->user->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $this->user->assignRole('owner');
});

it('renders campaigns page with seeded data', function () {
    CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $this->integration->id,
        'external_id' => 'c1',
        'name' => 'Test Newsletter',
        'sent' => 500,
        'opens' => 200,
        'clicks' => 50,
        'sent_at' => now()->subDays(5),
    ]);

    $this->actingAs($this->user)
        ->get(route('campaigns.emails'))
        ->assertOk()
        ->assertSee('Campaigns')
        ->assertSee('Test Newsletter');
});

it('shows empty state when no campaigns', function () {
    $this->actingAs($this->user)
        ->get(route('campaigns.emails'))
        ->assertOk()
        ->assertSee('No campaigns found');
});

it('enforces permission gate', function () {
    $viewer = User::factory()->create(['email_verified_at' => now()]);
    $viewer->organizations()->attach($this->org->id);
    $viewer->current_organization_id = $this->org->id;
    $viewer->save();

    $this->workspace->users()->attach($viewer->id);

    // User without any role cannot access
    $this->actingAs($viewer)
        ->get(route('campaigns.emails'))
        ->assertForbidden();
});
