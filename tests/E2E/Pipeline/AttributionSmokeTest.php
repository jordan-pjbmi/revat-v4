<?php

use App\Models\AttributionConnector;
use App\Models\AttributionKey;
use App\Models\AttributionResult;
use App\Models\CampaignEmail;
use App\Models\CampaignEmailClick;
use App\Models\ConversionSale;
use App\Models\Effort;
use App\Models\IdentityHash;
use App\Models\Initiative;
use App\Models\Organization;
use App\Models\Program;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AttributionEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organization::create(['name' => 'Attribution Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();

    $this->user = User::factory()->create([
        'current_organization_id' => $this->org->id,
    ]);
    $this->org->users()->attach($this->user);
    $this->workspace->users()->attach($this->user);

    // PIE hierarchy — default initiative for EffortResolver
    $program = Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Email Marketing',
        'code' => 'EM',
        'status' => 'active',
    ]);
    $initiative = Initiative::create([
        'workspace_id' => $this->workspace->id,
        'program_id' => $program->id,
        'name' => 'Welcome Series',
        'code' => 'WS',
        'is_default' => true,
    ]);
    $this->effort = Effort::create([
        'workspace_id' => $this->workspace->id,
        'initiative_id' => $initiative->id,
        'name' => 'Welcome Email',
        'code' => 'WE1',
        'channel_type' => 'email',
        'status' => 'active',
    ]);

    // Create integrations
    $this->campaignIntegration = createIntegration([
        'workspace_id' => $this->workspace->id,
        'organization_id' => $this->org->id,
        'name' => 'Campaign Source',
        'platform' => 'activecampaign',
        'data_types' => ['campaign_emails'],
        'is_active' => true,
        'sync_interval_minutes' => 60,
        'credentials' => ['api_key' => 'test-key'],
    ]);

    $this->conversionIntegration = createIntegration([
        'workspace_id' => $this->workspace->id,
        'organization_id' => $this->org->id,
        'name' => 'Conversion Source',
        'platform' => 'voluum',
        'data_types' => ['conversion_sales'],
        'is_active' => true,
        'sync_interval_minutes' => 60,
        'credentials' => ['api_key' => 'test-key'],
    ]);
});

it('creates attribution results for matching campaigns and conversions', function () {
    $sharedEmails = ['alice@example.com', 'bob@example.com'];

    // Create campaigns (no effort_id — resolved on keys by EffortResolver)
    $campaign1 = CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $this->campaignIntegration->id,
        'external_id' => 'camp-1',
        'name' => 'Welcome Alice',
        'from_email' => $sharedEmails[0],
        'sent_at' => now()->subDays(10),
    ]);

    $campaign2 = CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $this->campaignIntegration->id,
        'external_id' => 'camp-2',
        'name' => 'Welcome Bob',
        'from_email' => $sharedEmails[1],
        'sent_at' => now()->subDays(9),
    ]);

    // Create identity hashes and clicks
    $clicks = [];
    foreach ($sharedEmails as $index => $email) {
        $hash = hash('sha256', $email, true);
        $identityHash = IdentityHash::create([
            'workspace_id' => $this->workspace->id,
            'hash' => bin2hex($hash),
            'type' => 'email',
            'hash_algorithm' => 'sha256',
            'normalized_email_domain' => 'example.com',
        ]);

        $clicks[] = CampaignEmailClick::create([
            'workspace_id' => $this->workspace->id,
            'integration_id' => $this->campaignIntegration->id,
            'campaign_email_id' => $index === 0 ? $campaign1->id : $campaign2->id,
            'identity_hash_id' => $identityHash->id,
            'clicked_at' => now()->subDays(8 - $index),
        ]);
    }

    // Create conversions
    $conversion1 = ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $this->conversionIntegration->id,
        'external_id' => $sharedEmails[0],
        'revenue' => 100.00,
        'converted_at' => now()->subDays(3),
    ]);

    $conversion2 = ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'integration_id' => $this->conversionIntegration->id,
        'external_id' => $sharedEmails[1],
        'revenue' => 250.00,
        'converted_at' => now()->subDays(2),
    ]);

    // Create attribution connector
    $connector = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Email to Sale',
        'campaign_integration_id' => $this->campaignIntegration->id,
        'campaign_data_type' => 'email',
        'conversion_integration_id' => $this->conversionIntegration->id,
        'conversion_data_type' => 'sale',
        'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'external_id']],
        'is_active' => true,
    ]);

    // Attribution keys with effort_id set (simulating EffortResolver output)
    foreach ($sharedEmails as $index => $email) {
        $hash = hash('sha256', $email, true);
        $key = AttributionKey::create([
            'workspace_id' => $this->workspace->id,
            'connector_id' => $connector->id,
            'key_hash' => bin2hex($hash),
            'key_value' => $email,
            'effort_id' => $this->effort->id,
        ]);

        // Link campaign
        $campaign = $index === 0 ? $campaign1 : $campaign2;
        DB::table('attribution_record_keys')->insert([
            'connector_id' => $connector->id,
            'attribution_key_id' => $key->id,
            'record_type' => 'campaign_email',
            'record_id' => $campaign->id,
            'workspace_id' => $this->workspace->id,
        ]);

        // Link click
        DB::table('attribution_record_keys')->insert([
            'connector_id' => $connector->id,
            'attribution_key_id' => $key->id,
            'record_type' => 'campaign_email_click',
            'record_id' => $clicks[$index]->id,
            'workspace_id' => $this->workspace->id,
        ]);

        // Link conversion
        $conversion = $index === 0 ? $conversion1 : $conversion2;
        DB::table('attribution_record_keys')->insert([
            'connector_id' => $connector->id,
            'attribution_key_id' => $key->id,
            'record_type' => 'conversion_sale',
            'record_id' => $conversion->id,
            'workspace_id' => $this->workspace->id,
        ]);
    }

    // Run attribution engine directly (keys and record_keys are pre-created above)
    $engine = app(AttributionEngine::class);
    foreach (['first_touch', 'last_touch', 'linear'] as $model) {
        $engine->run($this->workspace, $connector, $model);
    }

    // Verify results were created
    $results = AttributionResult::where('workspace_id', $this->workspace->id)->get();
    expect($results->count())->toBeGreaterThan(0);

    // Verify all three models are present (first_touch, last_touch, linear)
    $models = $results->pluck('model')->unique()->sort()->values();
    expect($models->toArray())->toContain('first_touch');
    expect($models->toArray())->toContain('last_touch');
    expect($models->toArray())->toContain('linear');

    // Verify weights are positive
    foreach ($results as $result) {
        expect((float) $result->weight)->toBeGreaterThan(0);
    }
});
