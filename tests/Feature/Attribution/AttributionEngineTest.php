<?php

use App\Models\AttributionConnector;
use App\Models\AttributionKey;
use App\Models\AttributionResult;
use App\Models\CampaignEmail;
use App\Models\CampaignEmailClick;
use App\Models\ConversionSale;
use App\Models\Effort;
use App\Models\Initiative;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Workspace;
use App\Services\AttributionEngine;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();

    // PIE hierarchy
    $program = Program::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Test Program',
        'code' => 'TP',
    ]);
    $initiative = Initiative::create([
        'workspace_id' => $this->workspace->id,
        'program_id' => $program->id,
        'name' => 'Test Initiative',
        'code' => 'TI',
    ]);
    $this->effort1 = Effort::create([
        'workspace_id' => $this->workspace->id,
        'initiative_id' => $initiative->id,
        'name' => 'Effort A',
        'code' => 'EA',
        'channel_type' => 'email',
        'status' => 'active',
    ]);
    $this->effort2 = Effort::create([
        'workspace_id' => $this->workspace->id,
        'initiative_id' => $initiative->id,
        'name' => 'Effort B',
        'code' => 'EB',
        'channel_type' => 'email',
        'status' => 'active',
    ]);

    $this->connector = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Test Connector',
        'campaign_integration_id' => 1,
        'campaign_data_type' => 'email',
        'conversion_integration_id' => 2,
        'conversion_data_type' => 'sale',
        'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'external_id']],
    ]);

    $this->engine = app(AttributionEngine::class);
});

/**
 * Helper to set up a key + record key linkage.
 * Effort is now set on the key, not the campaign.
 */
function linkRecordToKey(int $connectorId, int $workspaceId, string $email, string $recordType, int $recordId, ?int $effortId = null): void
{
    $binaryHash = hash('sha256', $email, true);
    $hexHash = hash('sha256', $email);

    // Use raw query to find by binary hash (SQLite binary comparison workaround)
    $key = AttributionKey::where('connector_id', $connectorId)
        ->whereRaw('key_hash = ?', [$binaryHash])
        ->first();

    if (! $key) {
        $key = new AttributionKey;
        $key->workspace_id = $workspaceId;
        $key->connector_id = $connectorId;
        $key->key_hash = $hexHash; // BinaryHash cast converts to binary
        $key->key_value = $email;
        $key->save();
    }

    if ($effortId !== null && $key->effort_id !== $effortId) {
        $key->effort_id = $effortId;
        $key->save();
    }

    DB::table('attribution_record_keys')->updateOrInsert(
        ['connector_id' => $connectorId, 'record_type' => $recordType, 'record_id' => $recordId],
        ['attribution_key_id' => $key->id, 'workspace_id' => $workspaceId]
    );
}

it('first_touch: single match produces weight 1.0 with earliest click', function () {
    $email = 'alice@example.com';

    $campaign = CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'camp-1',
        'from_email' => $email,
    ]);
    $click = CampaignEmailClick::create([
        'workspace_id' => $this->workspace->id,
        'campaign_email_id' => $campaign->id,
        'clicked_at' => now()->subDays(5),
    ]);
    $conversion = ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'conv-1',
        'revenue' => 100,
        'converted_at' => now(),
    ]);

    linkRecordToKey($this->connector->id, $this->workspace->id, $email, 'campaign_email_click', $click->id, $this->effort1->id);
    linkRecordToKey($this->connector->id, $this->workspace->id, $email, 'conversion_sale', $conversion->id);

    $count = $this->engine->run($this->workspace, $this->connector, 'first_touch');

    expect($count)->toBe(1);

    $result = AttributionResult::first();
    expect($result->effort_id)->toBe($this->effort1->id);
    expect((float) $result->weight)->toBe(1.0);
    expect($result->model)->toBe('first_touch');
    expect($result->campaign_type)->toBe('campaign_email');
    expect($result->campaign_id)->toBe($campaign->id);
});

it('first_touch: multi-click selects earliest click (same effort via key)', function () {
    $email = 'bob@example.com';

    // Two campaigns sharing same key, with different click timestamps
    $campaign1 = CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'camp-1',
        'from_email' => $email,
    ]);
    $click1 = CampaignEmailClick::create([
        'workspace_id' => $this->workspace->id,
        'campaign_email_id' => $campaign1->id,
        'clicked_at' => now()->subDays(10), // Earlier
    ]);

    $campaign2 = CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'camp-2',
        'from_email' => $email,
    ]);
    $click2 = CampaignEmailClick::create([
        'workspace_id' => $this->workspace->id,
        'campaign_email_id' => $campaign2->id,
        'clicked_at' => now()->subDays(2), // Later
    ]);

    $conversion = ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'conv-1',
        'revenue' => 200,
        'converted_at' => now(),
    ]);

    // Both clicks share the same key (same email) → same effort
    linkRecordToKey($this->connector->id, $this->workspace->id, $email, 'campaign_email_click', $click1->id, $this->effort1->id);
    linkRecordToKey($this->connector->id, $this->workspace->id, $email, 'campaign_email_click', $click2->id);
    linkRecordToKey($this->connector->id, $this->workspace->id, $email, 'conversion_sale', $conversion->id);

    $count = $this->engine->run($this->workspace, $this->connector, 'first_touch');

    expect($count)->toBe(1);

    $result = AttributionResult::first();
    expect($result->effort_id)->toBe($this->effort1->id); // From key
    expect((float) $result->weight)->toBe(1.0);
    expect($result->campaign_type)->toBe('campaign_email');
    expect($result->campaign_id)->toBe($campaign1->id); // Earliest click → campaign1
});

it('first_touch: multi-effort via two connectors selects earliest click', function () {
    $email1 = 'alice@example.com';
    $email2 = 'bob@example.com';

    // Second connector for multi-effort scenario
    $connector2 = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Test Connector 2',
        'campaign_integration_id' => 1,
        'campaign_data_type' => 'campaign_email_clicks',
        'conversion_integration_id' => 2,
        'conversion_data_type' => 'sale',
        'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'external_id']],
    ]);

    $campaign1 = CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'camp-1',
        'from_email' => $email1,
    ]);
    $click1 = CampaignEmailClick::create([
        'workspace_id' => $this->workspace->id,
        'campaign_email_id' => $campaign1->id,
        'clicked_at' => now()->subDays(10), // Earlier
    ]);

    $campaign2 = CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'camp-2',
        'from_email' => $email2,
    ]);
    $click2 = CampaignEmailClick::create([
        'workspace_id' => $this->workspace->id,
        'campaign_email_id' => $campaign2->id,
        'clicked_at' => now()->subDays(2), // Later
    ]);

    $conversion = ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'conv-1',
        'revenue' => 200,
        'converted_at' => now(),
    ]);

    // Connector 1: email1 key → effort1
    linkRecordToKey($this->connector->id, $this->workspace->id, $email1, 'campaign_email_click', $click1->id, $this->effort1->id);
    linkRecordToKey($this->connector->id, $this->workspace->id, $email1, 'conversion_sale', $conversion->id);

    // Connector 2: email2 key → effort2
    linkRecordToKey($connector2->id, $this->workspace->id, $email2, 'campaign_email_click', $click2->id, $this->effort2->id);
    linkRecordToKey($connector2->id, $this->workspace->id, $email2, 'conversion_sale', $conversion->id);

    // Run both connectors
    $count1 = $this->engine->run($this->workspace, $this->connector, 'first_touch');
    $count2 = $this->engine->run($this->workspace, $connector2, 'first_touch');

    expect($count1)->toBe(1);
    expect($count2)->toBe(1);

    // Connector 1 produces effort1, connector 2 produces effort2
    $result1 = AttributionResult::where('connector_id', $this->connector->id)->first();
    $result2 = AttributionResult::where('connector_id', $connector2->id)->first();
    expect($result1->effort_id)->toBe($this->effort1->id);
    expect($result2->effort_id)->toBe($this->effort2->id);
    expect($result1->campaign_type)->toBe('campaign_email');
    expect($result1->campaign_id)->toBe($campaign1->id);
    expect($result2->campaign_type)->toBe('campaign_email');
    expect($result2->campaign_id)->toBe($campaign2->id);
});

it('first_touch: no match produces no result', function () {
    // Conversion with no matching campaign
    ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'conv-orphan',
        'revenue' => 50,
        'converted_at' => now(),
    ]);

    $count = $this->engine->run($this->workspace, $this->connector, 'first_touch');

    expect($count)->toBe(0);
    expect(AttributionResult::count())->toBe(0);
});

it('last_touch: single match produces weight 1.0 with latest click', function () {
    $email = 'charlie@example.com';

    $campaign = CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'camp-1',
        'from_email' => $email,
    ]);
    $click = CampaignEmailClick::create([
        'workspace_id' => $this->workspace->id,
        'campaign_email_id' => $campaign->id,
        'clicked_at' => now()->subDay(),
    ]);
    $conversion = ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'conv-1',
        'revenue' => 150,
        'converted_at' => now(),
    ]);

    linkRecordToKey($this->connector->id, $this->workspace->id, $email, 'campaign_email_click', $click->id, $this->effort1->id);
    linkRecordToKey($this->connector->id, $this->workspace->id, $email, 'conversion_sale', $conversion->id);

    $count = $this->engine->run($this->workspace, $this->connector, 'last_touch');

    expect($count)->toBe(1);
    $result = AttributionResult::first();
    expect((float) $result->weight)->toBe(1.0);
    expect($result->model)->toBe('last_touch');
    expect($result->campaign_type)->toBe('campaign_email');
    expect($result->campaign_id)->toBe($campaign->id);
});

it('last_touch: multi-click selects latest click (same effort via key)', function () {
    $email = 'diana@example.com';

    $campaign1 = CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'camp-1',
        'from_email' => $email,
    ]);
    $click1 = CampaignEmailClick::create([
        'workspace_id' => $this->workspace->id,
        'campaign_email_id' => $campaign1->id,
        'clicked_at' => now()->subDays(10), // Earlier
    ]);

    $campaign2 = CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'camp-2',
        'from_email' => $email,
    ]);
    $click2 = CampaignEmailClick::create([
        'workspace_id' => $this->workspace->id,
        'campaign_email_id' => $campaign2->id,
        'clicked_at' => now()->subDay(), // Later
    ]);

    $conversion = ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'conv-1',
        'revenue' => 300,
        'converted_at' => now(),
    ]);

    linkRecordToKey($this->connector->id, $this->workspace->id, $email, 'campaign_email_click', $click1->id, $this->effort1->id);
    linkRecordToKey($this->connector->id, $this->workspace->id, $email, 'campaign_email_click', $click2->id);
    linkRecordToKey($this->connector->id, $this->workspace->id, $email, 'conversion_sale', $conversion->id);

    $count = $this->engine->run($this->workspace, $this->connector, 'last_touch');

    expect($count)->toBe(1);
    $result = AttributionResult::first();
    expect($result->effort_id)->toBe($this->effort1->id); // Same effort from key
    expect($result->campaign_type)->toBe('campaign_email');
    expect($result->campaign_id)->toBe($campaign2->id); // Latest click → campaign2
});

it('linear: single match produces weight 1.0', function () {
    $email = 'eve@example.com';

    $campaign = CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'camp-1',
        'from_email' => $email,
    ]);
    $click = CampaignEmailClick::create([
        'workspace_id' => $this->workspace->id,
        'campaign_email_id' => $campaign->id,
        'clicked_at' => now()->subDay(),
    ]);
    $conversion = ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'conv-1',
        'revenue' => 100,
        'converted_at' => now(),
    ]);

    linkRecordToKey($this->connector->id, $this->workspace->id, $email, 'campaign_email_click', $click->id, $this->effort1->id);
    linkRecordToKey($this->connector->id, $this->workspace->id, $email, 'conversion_sale', $conversion->id);

    $count = $this->engine->run($this->workspace, $this->connector, 'linear');

    expect($count)->toBe(1);
    $result = AttributionResult::first();
    expect((float) $result->weight)->toBe(1.0);
    expect($result->campaign_type)->toBe('campaign_email');
    expect($result->campaign_id)->toBe($campaign->id);
});

it('linear: multi-effort via two connectors distributes weight across results', function () {
    $email1 = 'alice@example.com';
    $email2 = 'frank@example.com';

    $connector2 = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Test Connector 2',
        'campaign_integration_id' => 1,
        'campaign_data_type' => 'campaign_email_clicks',
        'conversion_integration_id' => 2,
        'conversion_data_type' => 'sale',
        'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'external_id']],
    ]);

    $campaign1 = CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'camp-1',
        'from_email' => $email1,
    ]);
    $click1 = CampaignEmailClick::create([
        'workspace_id' => $this->workspace->id,
        'campaign_email_id' => $campaign1->id,
        'clicked_at' => now()->subDays(5),
    ]);

    $campaign2 = CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'camp-2',
        'from_email' => $email2,
    ]);
    $click2 = CampaignEmailClick::create([
        'workspace_id' => $this->workspace->id,
        'campaign_email_id' => $campaign2->id,
        'clicked_at' => now()->subDays(2),
    ]);

    $conversion = ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'conv-1',
        'revenue' => 400,
        'converted_at' => now(),
    ]);

    // Connector 1: email1 key → effort1
    linkRecordToKey($this->connector->id, $this->workspace->id, $email1, 'campaign_email_click', $click1->id, $this->effort1->id);
    linkRecordToKey($this->connector->id, $this->workspace->id, $email1, 'conversion_sale', $conversion->id);

    // Connector 2: email2 key → effort2
    linkRecordToKey($connector2->id, $this->workspace->id, $email2, 'campaign_email_click', $click2->id, $this->effort2->id);
    linkRecordToKey($connector2->id, $this->workspace->id, $email2, 'conversion_sale', $conversion->id);

    // Each connector produces 1 result with weight 1.0 (single unique (effort_id, campaign_id) pair per connector)
    $count1 = $this->engine->run($this->workspace, $this->connector, 'linear');
    $count2 = $this->engine->run($this->workspace, $connector2, 'linear');

    expect($count1)->toBe(1);
    expect($count2)->toBe(1);

    $results = AttributionResult::where('model', 'linear')->get();
    expect($results)->toHaveCount(2);

    // Each has weight 1.0 (single unique (effort_id, campaign_id) pair per connector run)
    foreach ($results as $result) {
        expect((float) $result->weight)->toBe(1.0);
        expect($result->campaign_type)->toBe('campaign_email');
    }

    $result1 = AttributionResult::where('connector_id', $this->connector->id)->first();
    $result2 = AttributionResult::where('connector_id', $connector2->id)->first();
    expect($result1->campaign_id)->toBe($campaign1->id);
    expect($result2->campaign_id)->toBe($campaign2->id);
});

it('re-running clears previous results before writing new ones', function () {
    $email = 'grace@example.com';

    $campaign = CampaignEmail::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'camp-1',
        'from_email' => $email,
    ]);
    $click = CampaignEmailClick::create([
        'workspace_id' => $this->workspace->id,
        'campaign_email_id' => $campaign->id,
        'clicked_at' => now()->subDay(),
    ]);
    $conversion = ConversionSale::create([
        'workspace_id' => $this->workspace->id,
        'external_id' => 'conv-1',
        'revenue' => 100,
        'converted_at' => now(),
    ]);

    linkRecordToKey($this->connector->id, $this->workspace->id, $email, 'campaign_email_click', $click->id, $this->effort1->id);
    linkRecordToKey($this->connector->id, $this->workspace->id, $email, 'conversion_sale', $conversion->id);

    // Run twice
    $this->engine->run($this->workspace, $this->connector, 'first_touch');
    $countAfterFirst = AttributionResult::count();

    $this->engine->run($this->workspace, $this->connector, 'first_touch');
    $countAfterSecond = AttributionResult::count();

    // Should not accumulate — same count after re-run
    expect($countAfterSecond)->toBe($countAfterFirst);
    expect($countAfterSecond)->toBe(1);
});

it('rejects invalid attribution model', function () {
    expect(fn () => $this->engine->run($this->workspace, $this->connector, 'invalid_model'))
        ->toThrow(InvalidArgumentException::class);
});
