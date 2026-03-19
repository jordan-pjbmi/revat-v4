<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

class TestDataSeeder extends Seeder
{
    /**
     * Orchestrate test data seeding in dependency order.
     *
     * Creates a complete, interconnected dataset spanning the full pipeline:
     * Foundation → Billing → PIE → Integrations → Raw Data → Fact Tables → Attribution → Summaries
     *
     * Protected from running in production.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('TestDataSeeder cannot run in production. Set APP_ENV to a non-production value.');
        }

        $this->call([
            RolesAndPermissionsSeeder::class,
            PlanSeeder::class,
            Test\AdminSeeder::class,
            Test\FoundationSeeder::class,
            Test\BillingSeeder::class,
            Test\PieSeeder::class,
            Test\IntegrationSeeder::class,
            Test\RawDataSeeder::class,
            Test\FactTableSeeder::class,
            Test\AttributionSeeder::class,
            Test\SummarySeeder::class,
        ]);
    }
}
