<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'max_workspaces' => 1,
                'max_integrations_per_workspace' => 1,
                'max_users' => 1,
                'is_visible' => true,
                'sort_order' => 0,
            ],
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'max_workspaces' => 2,
                'max_integrations_per_workspace' => 3,
                'max_users' => 3,
                'is_visible' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Growth',
                'slug' => 'growth',
                'max_workspaces' => 5,
                'max_integrations_per_workspace' => 5,
                'max_users' => 10,
                'is_visible' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Agency',
                'slug' => 'agency',
                'max_workspaces' => 20,
                'max_integrations_per_workspace' => 10,
                'max_users' => 50,
                'is_visible' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'Alpha',
                'slug' => 'alpha',
                'max_workspaces' => 999,
                'max_integrations_per_workspace' => 999,
                'max_users' => 999,
                'is_visible' => false,
                'sort_order' => 0,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::firstOrCreate(
                ['slug' => $plan['slug']],
                $plan,
            );
        }
    }
}
