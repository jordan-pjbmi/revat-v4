<?php

namespace Database\Factories;

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardWidget>
 */
class DashboardWidgetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dashboard_id' => Dashboard::factory(),
            'widget_type' => fake()->randomElement(['metric', 'chart', 'table']),
            'grid_x' => fake()->numberBetween(0, 8),
            'grid_y' => fake()->numberBetween(0, 8),
            'grid_w' => fake()->numberBetween(2, 6),
            'grid_h' => fake()->numberBetween(2, 4),
            'config' => ['title' => fake()->words(2, true)],
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }
}
