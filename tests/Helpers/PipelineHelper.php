<?php

namespace Tests\Helpers;

use App\Models\AttributionConnector;
use App\Models\AttributionResult;
use App\Models\CampaignEmail;
use App\Models\CampaignEmailClick;
use App\Models\ConversionSale;
use App\Models\Effort;
use App\Models\ExtractionBatch;
use App\Models\ExtractionRecord;
use App\Models\IdentityHash;
use App\Models\Initiative;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\Program;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

trait PipelineHelper
{
    /**
     * Create org + user + workspace in one call.
     *
     * @return array{user: User, organization: Organization, workspace: Workspace}
     */
    protected function createOrganizationWithWorkspace(string $orgName = 'Test Org'): array
    {
        $organization = Organization::create(['name' => $orgName]);

        $workspace = new Workspace(['name' => 'Default']);
        $workspace->organization_id = $organization->id;
        $workspace->is_default = true;
        $workspace->save();

        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

        $organization->users()->attach($user);
        $workspace->users()->attach($user);

        return compact('user', 'organization', 'workspace');
    }

    /**
     * Create integration with extraction batch.
     */
    protected function createIntegrationWithBatch(
        Workspace $workspace,
        string $platform = 'activecampaign',
        string $dataType = 'campaign_emails',
        string $status = ExtractionBatch::STATUS_COMPLETED,
        int $recordCount = 5,
    ): array {
        $integration = new Integration([
            'name' => ucfirst($platform).' Integration',
            'platform' => $platform,
            'data_types' => [$dataType],
            'is_active' => true,
            'sync_interval_minutes' => 60,
        ]);
        $integration->workspace_id = $workspace->id;
        $integration->organization_id = $workspace->organization_id;
        $integration->credentials = ['api_key' => 'test-key'];
        $integration->save();

        $batch = ExtractionBatch::create([
            'integration_id' => $integration->id,
            'workspace_id' => $workspace->id,
            'data_type' => $dataType,
            'status' => $status,
        ]);

        $records = collect();
        for ($i = 0; $i < $recordCount; $i++) {
            $records->push(ExtractionRecord::create([
                'extraction_batch_id' => $batch->id,
                'external_id' => "ext-{$i}",
                'payload' => ['external_id' => "ext-{$i}", 'data' => "sample-{$i}"],
            ]));
        }

        return compact('integration', 'batch', 'records');
    }

    /**
     * Seed minimal full-pipeline data: extraction -> transform -> attribute -> summarize
     * for a single workspace.
     *
     * @return array<string, mixed>
     */
    protected function createFullPipelineData(?Workspace $workspace = null): array
    {
        if (! $workspace) {
            $ctx = $this->createOrganizationWithWorkspace();
            $workspace = $ctx['workspace'];
            $organization = $ctx['organization'];
            $user = $ctx['user'];
        } else {
            $organization = $workspace->organization;
            $user = null;
        }

        // PIE hierarchy
        $program = Program::create([
            'workspace_id' => $workspace->id,
            'name' => 'Test Program',
            'code' => 'TP',
            'status' => 'active',
        ]);

        $initiative = Initiative::create([
            'workspace_id' => $workspace->id,
            'program_id' => $program->id,
            'name' => 'Test Initiative',
            'code' => 'TI',
        ]);

        $effort = Effort::create([
            'workspace_id' => $workspace->id,
            'initiative_id' => $initiative->id,
            'name' => 'Test Effort',
            'code' => 'TE1',
            'channel_type' => 'email',
            'status' => 'active',
        ]);

        // Integrations
        $campaignSetup = $this->createIntegrationWithBatch($workspace, 'activecampaign', 'campaign_emails');
        $conversionSetup = $this->createIntegrationWithBatch($workspace, 'voluum', 'conversion_sales');

        // Campaign emails
        $campaigns = collect();
        for ($i = 0; $i < 3; $i++) {
            $campaigns->push(CampaignEmail::create([
                'workspace_id' => $workspace->id,
                'integration_id' => $campaignSetup['integration']->id,
                'external_id' => "camp-{$i}",
                'name' => "Campaign {$i}",
                'from_email' => "sender{$i}@example.com",
                'sent' => 1000,
                'opens' => 300,
                'clicks' => 50,
                'sent_at' => now()->subDays($i + 1),
            ]));
        }

        // Identity hashes and clicks
        $clicks = collect();
        for ($i = 0; $i < 5; $i++) {
            $hash = hash('sha256', "user{$i}@example.com", true);
            $identityHash = IdentityHash::create([
                'workspace_id' => $workspace->id,
                'hash' => bin2hex($hash),
                'type' => 'email',
                'hash_algorithm' => 'sha256',
                'normalized_email_domain' => 'example.com',
            ]);

            $clicks->push(CampaignEmailClick::create([
                'workspace_id' => $workspace->id,
                'integration_id' => $campaignSetup['integration']->id,
                'campaign_email_id' => $campaigns->random()->id,
                'identity_hash_id' => $identityHash->id,
                'clicked_at' => now()->subDays($i),
            ]));
        }

        // Conversions
        $conversions = collect();
        for ($i = 0; $i < 3; $i++) {
            $conversions->push(ConversionSale::create([
                'workspace_id' => $workspace->id,
                'integration_id' => $conversionSetup['integration']->id,
                'external_id' => "conv-{$i}",
                'revenue' => ($i + 1) * 100,
                'converted_at' => now()->subDays($i),
            ]));
        }

        // Attribution
        $connector = AttributionConnector::create([
            'workspace_id' => $workspace->id,
            'name' => 'Test Connector',
            'campaign_integration_id' => $campaignSetup['integration']->id,
            'campaign_data_type' => 'email',
            'conversion_integration_id' => $conversionSetup['integration']->id,
            'conversion_data_type' => 'sale',
            'field_mappings' => [['campaign' => 'from_email', 'conversion' => 'external_id']],
            'is_active' => true,
        ]);

        // Attribution keys and results
        $attributionResults = collect();
        foreach ($conversions as $conversion) {
            foreach (['first_touch', 'last_touch', 'linear'] as $model) {
                $attributionResults->push(AttributionResult::create([
                    'workspace_id' => $workspace->id,
                    'connector_id' => $connector->id,
                    'conversion_type' => 'conversion_sale',
                    'conversion_id' => $conversion->id,
                    'campaign_type' => 'campaign_email',
                    'campaign_id' => $campaigns->random()->id,
                    'effort_id' => $effort->id,
                    'model' => $model,
                    'weight' => 1.0,
                    'matched_at' => now(),
                ]));
            }
        }

        // Summary data
        for ($d = 0; $d < 7; $d++) {
            $date = now()->subDays($d)->toDateString();

            DB::table('summary_campaign_daily')->insert([
                'workspace_id' => $workspace->id,
                'summary_date' => $date,
                'campaigns_count' => 1,
                'sent' => 1000,
                'delivered' => 950,
                'bounced' => 20,
                'complaints' => 1,
                'unsubscribes' => 5,
                'opens' => 300,
                'unique_opens' => 200,
                'clicks' => 50,
                'unique_clicks' => 30,
                'platform_revenue' => 100.00,
                'summarized_at' => now(),
            ]);

            DB::table('summary_conversion_daily')->insert([
                'workspace_id' => $workspace->id,
                'summary_date' => $date,
                'conversions_count' => 3,
                'revenue' => 600.00,
                'payout' => 200.00,
                'cost' => 50.00,
                'summarized_at' => now(),
            ]);

            foreach (['first_touch', 'last_touch', 'linear'] as $model) {
                DB::table('summary_attribution_daily')->insert([
                    'workspace_id' => $workspace->id,
                    'summary_date' => $date,
                    'model' => $model,
                    'attributed_conversions' => 3,
                    'attributed_revenue' => 600.00,
                    'total_weight' => 3.0,
                    'summarized_at' => now(),
                ]);
            }

            DB::table('summary_workspace_daily')->insert([
                'workspace_id' => $workspace->id,
                'summary_date' => $date,
                'campaigns_count' => 1,
                'sent' => 1000,
                'opens' => 300,
                'clicks' => 50,
                'conversions_count' => 3,
                'revenue' => 600.00,
                'cost' => 50.00,
                'summarized_at' => now(),
            ]);
        }

        return [
            'workspace' => $workspace,
            'organization' => $organization,
            'user' => $user,
            'program' => $program,
            'initiative' => $initiative,
            'effort' => $effort,
            'campaigns' => $campaigns,
            'clicks' => $clicks,
            'conversions' => $conversions,
            'connector' => $connector,
            'attribution_results' => $attributionResults,
        ];
    }
}
