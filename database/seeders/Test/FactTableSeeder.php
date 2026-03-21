<?php

namespace Database\Seeders\Test;

use App\Models\CampaignEmail;
use App\Models\CampaignEmailClick;
use App\Models\CampaignEmailRawData;
use App\Models\ConversionSale;
use App\Models\ConversionSaleRawData;
use App\Models\IdentityHash;
use App\Models\Integration;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

class FactTableSeeder extends Seeder
{
    /**
     * Seed campaign_emails (20/ws), campaign_email_clicks (50/ws),
     * and conversion_sales (30/ws) with identity hashes.
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

            $rawCampaigns = CampaignEmailRawData::where('workspace_id', $workspace->id)
                ->orderBy('id')
                ->limit(20)
                ->get();

            $rawConversions = ConversionSaleRawData::where('workspace_id', $workspace->id)
                ->orderBy('id')
                ->limit(30)
                ->get();

            // Seed campaign emails
            $campaigns = collect();
            for ($i = 0; $i < 20; $i++) {
                $sentAt = now()->subDays(fake()->numberBetween(1, 30));
                $rawData = $rawCampaigns->get($i);

                $campaign = CampaignEmail::create([
                    'workspace_id' => $workspace->id,
                    'raw_data_id' => $rawData?->id,
                    'integration_id' => $campaignIntegration?->id,
                    'external_id' => "camp-{$workspace->id}-{$i}",
                    'name' => fake()->words(3, true),
                    'subject' => fake()->sentence(),
                    'from_name' => fake()->name(),
                    'from_email' => "sender{$i}@example.com",
                    'type' => 'regular',
                    'sent' => $sent = fake()->numberBetween(500, 10000),
                    'delivered' => (int) ($sent * 0.95),
                    'bounced' => (int) ($sent * 0.02),
                    'complaints' => fake()->numberBetween(0, 5),
                    'unsubscribes' => fake()->numberBetween(0, 20),
                    'opens' => fake()->numberBetween(100, (int) ($sent * 0.4)),
                    'unique_opens' => fake()->numberBetween(50, (int) ($sent * 0.3)),
                    'clicks' => fake()->numberBetween(10, (int) ($sent * 0.1)),
                    'unique_clicks' => fake()->numberBetween(5, (int) ($sent * 0.08)),
                    'platform_revenue' => fake()->randomFloat(2, 0, 1000),
                    'sent_at' => $sentAt,
                    'transformed_at' => now(),
                ]);

                $campaigns->push($campaign);
            }

            // Seed identity hashes and campaign email clicks
            for ($i = 0; $i < 50; $i++) {
                $email = "subscriber{$i}@example.com";
                $hash = hash('sha256', $email, true);

                $identityHash = IdentityHash::create([
                    'workspace_id' => $workspace->id,
                    'hash' => bin2hex($hash),
                    'type' => 'email',
                    'hash_algorithm' => 'sha256',
                    'normalized_email_domain' => 'example.com',
                ]);

                CampaignEmailClick::create([
                    'workspace_id' => $workspace->id,
                    'integration_id' => $campaignIntegration?->id,
                    'campaign_email_id' => $campaigns->random()->id,
                    'identity_hash_id' => $identityHash->id,
                    'clicked_at' => now()->subDays(fake()->numberBetween(1, 30)),
                    'transformed_at' => now(),
                ]);
            }

            // Seed conversion sales
            for ($i = 0; $i < 30; $i++) {
                $rawData = $rawConversions->get($i);

                // Reuse identity hashes from clicks for matching
                $identityHash = IdentityHash::where('workspace_id', $workspace->id)
                    ->inRandomOrder()
                    ->first();

                ConversionSale::create([
                    'workspace_id' => $workspace->id,
                    'raw_data_id' => $rawData?->id,
                    'integration_id' => $conversionIntegration?->id,
                    'external_id' => "conv-{$workspace->id}-{$i}",
                    'identity_hash_id' => $identityHash?->id,
                    'revenue' => fake()->randomFloat(2, 10, 500),
                    'payout' => fake()->randomFloat(2, 5, 200),
                    'cost' => fake()->randomFloat(2, 1, 50),
                    'converted_at' => now()->subDays(fake()->numberBetween(1, 30)),
                    'transformed_at' => now(),
                ]);
            }
        }
    }
}
