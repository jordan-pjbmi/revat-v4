<?php

namespace Database\Factories;

use App\Models\Dashboard;
use App\Models\DashboardSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardSnapshot>
 */
class DashboardSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dashboard_id' => Dashboard::factory(),
            'created_by' => User::factory(),
            'layout' => [],
            'widget_count' => fake()->numberBetween(0, 10),
            'created_at' => now(),
        ];
    }
}
