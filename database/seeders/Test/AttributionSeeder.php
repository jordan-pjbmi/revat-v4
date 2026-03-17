<?php

namespace Database\Seeders\Test;

use App\Models\AttributionConnector;
use App\Models\AttributionKey;
use App\Models\AttributionResult;
use App\Models\CampaignEmail;
use App\Models\CampaignEmailClick;
use App\Models\ConversionSale;
use App\Models\Effort;
use App\Models\Integration;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AttributionSeeder extends Seeder
{
    /**
     * Seed attribution connectors, keys, record keys, and results.
     */
    public function run(): void
    {
        $workspaces = Workspace::orderBy('id')->get();

        foreach ($workspaces as $workspace) {
            $campaignIntegration = Integration::where('workspace_id', $workspace->id)
                ->where('platform', 'activecampaign')
                ->first();

            $conversionIntegration = Integration::where('workspace_id', $workspace->id)
                ->where('platform', 'voluum')
                ->first();

            if (! $campaignIntegration || ! $conversionIntegration) {
                continue;
            }

            // Create attribution connector
            $connector = AttributionConnector::create([
                'workspace_id' => $workspace->id,
                'name' => 'Email to Sale',
                'campaign_integration_id' => $campaignIntegration->id,
                'campaign_data_type' => 'email',
                'conversion_integration_id' => $conversionIntegration->id,
                'conversion_data_type' => 'sale',
                'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'external_id']],
                'is_active' => true,
            ]);

            // Get campaigns and conversions
            $campaigns = CampaignEmail::where('workspace_id', $workspace->id)->get();
            $clicks = CampaignEmailClick::where('workspace_id', $workspace->id)->get();
            $conversions = ConversionSale::where('workspace_id', $workspace->id)->get();

            // Create attribution keys from campaign from_email fields
            $emailKeys = [];
            foreach ($campaigns as $campaign) {
                $email = $campaign->from_email;
                if (isset($emailKeys[$email])) {
                    continue;
                }

                $hash = hash('sha256', $email, true);
                $key = AttributionKey::create([
                    'workspace_id' => $workspace->id,
                    'connector_id' => $connector->id,
                    'key_hash' => bin2hex($hash),
                    'key_value' => $email,
                ]);

                $emailKeys[$email] = $key;
            }

            // Link campaigns to keys via record keys
            foreach ($campaigns as $campaign) {
                $key = $emailKeys[$campaign->from_email] ?? null;
                if (! $key) {
                    continue;
                }

                DB::table('attribution_record_keys')->insert([
                    'connector_id' => $connector->id,
                    'attribution_key_id' => $key->id,
                    'record_type' => 'campaign_email',
                    'record_id' => $campaign->id,
                    'workspace_id' => $workspace->id,
                ]);
            }

            // Link clicks to keys
            foreach ($clicks as $click) {
                $campaign = $campaigns->firstWhere('id', $click->campaign_email_id);
                if (! $campaign) {
                    continue;
                }

                $key = $emailKeys[$campaign->from_email] ?? null;
                if (! $key) {
                    continue;
                }

                DB::table('attribution_record_keys')->insert([
                    'connector_id' => $connector->id,
                    'attribution_key_id' => $key->id,
                    'record_type' => 'campaign_email_click',
                    'record_id' => $click->id,
                    'workspace_id' => $workspace->id,
                ]);
            }

            // Link conversions to random keys (simulating matches)
            $keyValues = collect($emailKeys)->values();
            foreach ($conversions as $conversion) {
                $key = $keyValues->random();

                DB::table('attribution_record_keys')->insert([
                    'connector_id' => $connector->id,
                    'attribution_key_id' => $key->id,
                    'record_type' => 'conversion_sale',
                    'record_id' => $conversion->id,
                    'workspace_id' => $workspace->id,
                ]);
            }

            // Create attribution results
            $models = ['first_touch', 'last_touch', 'linear'];
            $efforts = Effort::where('workspace_id', $workspace->id)->get();

            foreach ($conversions as $conversion) {
                foreach ($models as $model) {
                    $effort = $efforts->isNotEmpty() ? $efforts->random() : null;
                    $weight = $model === 'linear' ? round(1 / max(1, fake()->numberBetween(1, 3)), 4) : 1.0;

                    AttributionResult::create([
                        'workspace_id' => $workspace->id,
                        'connector_id' => $connector->id,
                        'conversion_type' => 'conversion_sale',
                        'conversion_id' => $conversion->id,
                        'campaign_type' => 'campaign_email',
                        'campaign_id' => $campaigns->random()->id,
                        'effort_id' => $effort?->id,
                        'model' => $model,
                        'weight' => $weight,
                        'matched_at' => now(),
                    ]);
                }
            }
        }
    }
}
