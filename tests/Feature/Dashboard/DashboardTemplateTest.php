<?php

use App\Models\Dashboard;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DashboardTemplateSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->organization = Organization::create(['name' => 'Test Org']);
    $workspace = new Workspace(['name' => 'Test Workspace']);
    $workspace->organization_id = $this->organization->id;
    $workspace->save();
    $this->workspace = $workspace;
    $this->user = User::factory()->create(['current_organization_id' => $this->organization->id]);
    setPermissionsTeamId($this->organization->id);
    $this->user->assignRole('owner');
});

it('seeder creates 3 template dashboards', function () {
    $this->seed(DashboardTemplateSeeder::class);

    expect(Dashboard::templates()->count())->toBe(3);
});

it('templates have is_template true and workspace_id null', function () {
    $this->seed(DashboardTemplateSeeder::class);

    $templates = Dashboard::templates()->get();

    foreach ($templates as $template) {
        expect($template->is_template)->toBeTrue();
        expect($template->workspace_id)->toBeNull();
    }
});

it('executive overview template has 6 widgets', function () {
    $this->seed(DashboardTemplateSeeder::class);

    $dashboard = Dashboard::where('template_slug', 'executive-overview')->first();

    expect($dashboard)->not->toBeNull();
    expect($dashboard->widgets)->toHaveCount(6);
});

it('campaign manager template has 5 widgets', function () {
    $this->seed(DashboardTemplateSeeder::class);

    $dashboard = Dashboard::where('template_slug', 'campaign-manager')->first();

    expect($dashboard)->not->toBeNull();
    expect($dashboard->widgets)->toHaveCount(5);
});

it('attribution analyst template has 5 widgets', function () {
    $this->seed(DashboardTemplateSeeder::class);

    $dashboard = Dashboard::where('template_slug', 'attribution-analyst')->first();

    expect($dashboard)->not->toBeNull();
    expect($dashboard->widgets)->toHaveCount(5);
});

it('seeder is idempotent and does not duplicate on second run', function () {
    $this->seed(DashboardTemplateSeeder::class);
    $this->seed(DashboardTemplateSeeder::class);

    expect(Dashboard::templates()->count())->toBe(3);
    expect(Dashboard::where('template_slug', 'executive-overview')->count())->toBe(1);
    expect(Dashboard::where('template_slug', 'campaign-manager')->count())->toBe(1);
    expect(Dashboard::where('template_slug', 'attribution-analyst')->count())->toBe(1);
});

it('cloning a template creates a workspace dashboard with correct widget count', function () {
    $this->seed(DashboardTemplateSeeder::class);

    $template = Dashboard::where('template_slug', 'executive-overview')->first();
    $clone = $template->cloneToWorkspace($this->workspace->id, $this->user->id);

    expect($clone->is_template)->toBeFalse();
    expect($clone->workspace_id)->toBe($this->workspace->id);
    expect($clone->template_slug)->toBeNull();
    expect($clone->widgets)->toHaveCount(6);
    expect($clone->id)->not->toBe($template->id);
});
