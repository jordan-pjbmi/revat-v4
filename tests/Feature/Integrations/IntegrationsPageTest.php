<?php

use App\Jobs\Extraction\ExtractIntegration;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
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

it('renders integrations page successfully', function () {
    $this->actingAs($this->user)
        ->get(route('integrations'))
        ->assertOk()
        ->assertSee('Integrations')
        ->assertSee('Manage your data source connections');
});

it('shows empty state when no integrations', function () {
    $this->actingAs($this->user)
        ->get(route('integrations'))
        ->assertOk()
        ->assertSee('No integrations configured');
});

it('displays integration details', function () {
    $integration = new Integration([
        'name' => 'My ActiveCampaign',
        'platform' => 'activecampaign',
        'data_types' => ['campaign_emails', 'campaign_email_clicks'],
        'is_active' => true,
        'sync_interval_minutes' => 60,
    ]);
    $integration->workspace_id = $this->workspace->id;
    $integration->organization_id = $this->org->id;
    $integration->last_sync_status = 'completed';
    $integration->last_synced_at = now()->subMinutes(30);
    $integration->save();

    $this->actingAs($this->user)
        ->get(route('integrations'))
        ->assertOk()
        ->assertSee('My ActiveCampaign')
        ->assertSee('ActiveCampaign')
        ->assertSee('Active')
        ->assertSee('Completed');
});

it('shows inactive badge for inactive integration', function () {
    $integration = new Integration([
        'name' => 'Disabled Integration',
        'platform' => 'voluum',
        'data_types' => ['conversion_sales'],
        'is_active' => false,
        'sync_interval_minutes' => 60,
    ]);
    $integration->workspace_id = $this->workspace->id;
    $integration->organization_id = $this->org->id;
    $integration->save();

    $this->actingAs($this->user)
        ->get(route('integrations'))
        ->assertOk()
        ->assertSee('Disabled Integration')
        ->assertSee('Inactive');
});

it('requires authentication', function () {
    $this->get(route('integrations'))
        ->assertRedirect(route('login'));
});

it('shows add integration link', function () {
    $this->actingAs($this->user)
        ->get(route('integrations'))
        ->assertOk()
        ->assertSee('Add Integration');
});

it('can create an integration via the wizard', function () {
    Queue::fake([ExtractIntegration::class]);

    $this->actingAs($this->user);
    app(WorkspaceContext::class)->setWorkspace($this->workspace);

    Volt::test('integrations.create')
        ->call('selectPlatform', 'activecampaign')
        ->assertSet('step', 2)
        ->set('name', 'Test AC Integration')
        ->set('credentials.api_url', 'https://test.api-us1.com')
        ->set('credentials.api_key', 'test-key-123')
        ->call('continueFromCredentials')
        ->assertSet('step', 3)
        ->set('connectionTestResult', ['success' => true, 'message' => 'OK', 'accountName' => 'test'])
        ->call('continueFromTest')
        ->assertSet('step', 4)
        ->set('selectedDataTypes', ['campaign_emails'])
        ->set('syncInterval', 60)
        ->call('saveIntegration')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('integrations', [
        'name' => 'Test AC Integration',
        'platform' => 'activecampaign',
        'workspace_id' => $this->workspace->id,
        'organization_id' => $this->org->id,
        'sync_interval_minutes' => 60,
        'is_active' => true,
    ]);

    Queue::assertPushed(ExtractIntegration::class);
});

it('validates required fields when creating integration', function () {
    $this->actingAs($this->user);
    app(WorkspaceContext::class)->setWorkspace($this->workspace);

    Volt::test('integrations.create')
        ->call('selectPlatform', 'activecampaign')
        ->set('name', '')
        ->call('continueFromCredentials')
        ->assertHasErrors(['name']);
});

it('validates credential fields for selected platform', function () {
    $this->actingAs($this->user);
    app(WorkspaceContext::class)->setWorkspace($this->workspace);

    Volt::test('integrations.create')
        ->call('selectPlatform', 'activecampaign')
        ->set('name', 'Test Integration')
        ->call('continueFromCredentials')
        ->assertHasErrors(['credentials.api_url', 'credentials.api_key']);
});

it('requires at least one data type on save', function () {
    $this->actingAs($this->user);
    app(WorkspaceContext::class)->setWorkspace($this->workspace);

    Volt::test('integrations.create')
        ->call('selectPlatform', 'activecampaign')
        ->set('name', 'Test Integration')
        ->set('credentials.api_url', 'https://test.api-us1.com')
        ->set('credentials.api_key', 'test-key-123')
        ->call('continueFromCredentials')
        ->set('connectionTestResult', ['success' => true, 'message' => 'OK', 'accountName' => 'test'])
        ->call('continueFromTest')
        ->set('selectedDataTypes', [])
        ->call('saveIntegration')
        ->assertHasErrors(['selectedDataTypes']);
});

it('selects platform and loads defaults', function () {
    $this->actingAs($this->user);
    app(WorkspaceContext::class)->setWorkspace($this->workspace);

    $component = Volt::test('integrations.create')
        ->call('selectPlatform', 'voluum');

    expect($component->get('platform'))->toBe('voluum');
    expect($component->get('selectedDataTypes'))->toBe(['conversion_sales']);
    expect($component->get('step'))->toBe(2);
});

it('cannot skip ahead past untouched steps', function () {
    $this->actingAs($this->user);
    app(WorkspaceContext::class)->setWorkspace($this->workspace);

    Volt::test('integrations.create')
        ->assertSet('step', 1)
        ->call('goToStep', 3)
        ->assertSet('step', 1);
});

it('enforces integrate permission', function () {
    $viewer = User::factory()->create(['email_verified_at' => now()]);
    $viewer->organizations()->attach($this->org->id);
    $viewer->current_organization_id = $this->org->id;
    $viewer->save();

    $this->workspace->users()->attach($viewer->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    $viewer->assignRole('viewer');

    $this->actingAs($viewer)
        ->get(route('integrations'))
        ->assertForbidden();
});
