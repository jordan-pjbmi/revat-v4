<?php

use App\Models\AttributionConnector;
use App\Models\AttributionKey;
use App\Models\AttributionRecordKey;
use App\Models\AttributionResult;
use App\Models\Effort;
use App\Models\Initiative;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Workspace;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Test Org']);
    $this->workspace = new Workspace(['name' => 'Default']);
    $this->workspace->organization_id = $this->org->id;
    $this->workspace->is_default = true;
    $this->workspace->save();
});

it('prevents duplicate connectors for same integration pair and data types', function () {
    $attrs = [
        'workspace_id' => $this->workspace->id,
        'name' => 'Test Connector',
        'campaign_integration_id' => 1,
        'campaign_data_type' => 'email',
        'conversion_integration_id' => 2,
        'conversion_data_type' => 'sale',
        'field_mappings' => [['campaign' => 'email', 'conversion' => 'customer_email']],
    ];

    AttributionConnector::create($attrs);

    expect(fn () => AttributionConnector::create(array_merge($attrs, ['name' => 'Duplicate'])))
        ->toThrow(QueryException::class);
});

it('prevents duplicate attribution for same conversion/effort/model', function () {
    $connector = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Test Connector',
        'campaign_integration_id' => 1,
        'campaign_data_type' => 'email',
        'conversion_integration_id' => 2,
        'conversion_data_type' => 'sale',
        'field_mappings' => [['campaign' => 'email', 'conversion' => 'customer_email']],
    ]);

    $program = Program::create(['workspace_id' => $this->workspace->id, 'name' => 'Test Program', 'code' => 'TST']);
    $initiative = Initiative::create(['program_id' => $program->id, 'workspace_id' => $this->workspace->id, 'name' => 'Test Initiative', 'code' => 'TST']);
    $effort = new Effort(['name' => 'Test Effort', 'code' => 'TST']);
    $effort->initiative_id = $initiative->id;
    $effort->workspace_id = $this->workspace->id;
    $effort->save();

    $attrs = [
        'workspace_id' => $this->workspace->id,
        'connector_id' => $connector->id,
        'conversion_type' => 'conversion_sale',
        'conversion_id' => 1,
        'campaign_type' => 'campaign_email',
        'campaign_id' => 1,
        'effort_id' => $effort->id,
        'model' => 'last_touch',
        'weight' => 1.0,
        'matched_at' => now(),
    ];

    AttributionResult::create($attrs);

    expect(fn () => AttributionResult::create($attrs))
        ->toThrow(QueryException::class);
});

it('attribution_record_keys has no auto-increment PK', function () {
    $connector = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Test Connector',
        'campaign_integration_id' => 1,
        'campaign_data_type' => 'email',
        'conversion_integration_id' => 2,
        'conversion_data_type' => 'sale',
        'field_mappings' => [['campaign' => 'email', 'conversion' => 'customer_email']],
    ]);

    $key = AttributionKey::create([
        'workspace_id' => $this->workspace->id,
        'connector_id' => $connector->id,
        'key_hash' => hash('sha256', 'test@example.com'),
        'key_value' => 'test@example.com',
    ]);

    $recordKey = new AttributionRecordKey;
    $recordKey->connector_id = $connector->id;
    $recordKey->attribution_key_id = $key->id;
    $recordKey->record_type = 'campaign_email';
    $recordKey->record_id = 1;
    $recordKey->workspace_id = $this->workspace->id;
    $recordKey->save();

    expect($recordKey->incrementing)->toBeFalse();

    $found = AttributionRecordKey::where('connector_id', $connector->id)
        ->where('record_type', 'campaign_email')
        ->where('record_id', 1)
        ->first();

    expect($found)->not->toBeNull();
});

it('effort with attribution results cannot be deleted (restrict)', function () {
    $connector = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Test Connector',
        'campaign_integration_id' => 1,
        'campaign_data_type' => 'email',
        'conversion_integration_id' => 2,
        'conversion_data_type' => 'sale',
        'field_mappings' => [['campaign' => 'email', 'conversion' => 'customer_email']],
    ]);

    $program = Program::create(['workspace_id' => $this->workspace->id, 'name' => 'Test Program', 'code' => 'TST']);
    $initiative = Initiative::create(['program_id' => $program->id, 'workspace_id' => $this->workspace->id, 'name' => 'Test Initiative', 'code' => 'TST']);
    $effort = new Effort(['name' => 'Test Effort', 'code' => 'TST']);
    $effort->initiative_id = $initiative->id;
    $effort->workspace_id = $this->workspace->id;
    $effort->save();

    AttributionResult::create([
        'workspace_id' => $this->workspace->id,
        'connector_id' => $connector->id,
        'conversion_type' => 'conversion_sale',
        'conversion_id' => 1,
        'campaign_type' => 'campaign_email',
        'campaign_id' => 1,
        'effort_id' => $effort->id,
        'model' => 'last_touch',
        'weight' => 1.0,
        'matched_at' => now(),
    ]);

    expect(fn () => $effort->forceDelete())->toThrow(QueryException::class);
});

it('model relationships and scopes work correctly', function () {
    $connector = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Active Connector',
        'campaign_integration_id' => 1,
        'campaign_data_type' => 'email',
        'conversion_integration_id' => 2,
        'conversion_data_type' => 'sale',
        'field_mappings' => [['campaign' => 'email', 'conversion' => 'customer_email']],
        'is_active' => true,
    ]);

    AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Inactive Connector',
        'campaign_integration_id' => 3,
        'campaign_data_type' => 'email',
        'conversion_integration_id' => 4,
        'conversion_data_type' => 'sale',
        'field_mappings' => [['campaign' => 'email', 'conversion' => 'customer_email']],
        'is_active' => false,
    ]);

    // Active scope
    expect(AttributionConnector::active()->count())->toBe(1);
    expect(AttributionConnector::active()->first()->name)->toBe('Active Connector');

    // Workspace relationship
    expect($connector->workspace->id)->toBe($this->workspace->id);

    // Casts
    expect($connector->field_mappings)->toBeArray();
    expect($connector->is_active)->toBeTrue();
});

it('BinaryHash cast converts hex/binary correctly', function () {
    $connector = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Test Connector',
        'campaign_integration_id' => 1,
        'campaign_data_type' => 'email',
        'conversion_integration_id' => 2,
        'conversion_data_type' => 'sale',
        'field_mappings' => [['campaign' => 'email', 'conversion' => 'customer_email']],
    ]);

    $hexHash = hash('sha256', 'test@example.com');

    $key = AttributionKey::create([
        'workspace_id' => $this->workspace->id,
        'connector_id' => $connector->id,
        'key_hash' => $hexHash,
        'key_value' => 'test@example.com',
    ]);

    // Reload from DB
    $key->refresh();

    // key_hash should come back as hex
    expect($key->key_hash)->toBe($hexHash);
});

it('AttributionResult scopes filter correctly', function () {
    $connector = AttributionConnector::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Test Connector',
        'campaign_integration_id' => 1,
        'campaign_data_type' => 'email',
        'conversion_integration_id' => 2,
        'conversion_data_type' => 'sale',
        'field_mappings' => [['campaign' => 'email', 'conversion' => 'customer_email']],
    ]);

    $program = Program::create(['workspace_id' => $this->workspace->id, 'name' => 'Test Program', 'code' => 'TST']);
    $initiative = Initiative::create(['program_id' => $program->id, 'workspace_id' => $this->workspace->id, 'name' => 'Test Initiative', 'code' => 'TST']);
    $effort = new Effort(['name' => 'Test Effort', 'code' => 'TST']);
    $effort->initiative_id = $initiative->id;
    $effort->workspace_id = $this->workspace->id;
    $effort->save();

    AttributionResult::create([
        'workspace_id' => $this->workspace->id,
        'connector_id' => $connector->id,
        'conversion_type' => 'conversion_sale',
        'conversion_id' => 1,
        'campaign_type' => 'campaign_email',
        'campaign_id' => 1,
        'effort_id' => $effort->id,
        'model' => 'last_touch',
        'weight' => 1.0,
        'matched_at' => now(),
    ]);

    AttributionResult::create([
        'workspace_id' => $this->workspace->id,
        'connector_id' => $connector->id,
        'conversion_type' => 'conversion_sale',
        'conversion_id' => 2,
        'campaign_type' => 'campaign_email',
        'campaign_id' => 1,
        'effort_id' => $effort->id,
        'model' => 'first_touch',
        'weight' => 1.0,
        'matched_at' => now(),
    ]);

    expect(AttributionResult::forModel('last_touch')->count())->toBe(1);
    expect(AttributionResult::forConversion('conversion_sale', 1)->count())->toBe(1);
});
