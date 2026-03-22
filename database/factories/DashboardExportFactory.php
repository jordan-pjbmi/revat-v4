<?php

namespace Database\Factories;

use App\Models\Dashboard;
use App\Models\DashboardExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DashboardExport>
 */
class DashboardExportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dashboard_id' => Dashboard::factory(),
            'created_by' => User::factory(),
            'token' => Str::random(64),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'layout' => [],
            'widget_count' => fake()->numberBetween(0, 10),
            'expires_at' => null,
            'created_at' => now(),
        ];
    }
}
