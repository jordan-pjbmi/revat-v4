<?php

namespace Database\Factories;

use App\Models\Dashboard;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dashboard>
 */
class DashboardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'created_by' => User::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'is_template' => false,
            'template_slug' => null,
            'is_locked' => false,
        ];
    }

    public function template(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_template' => true,
            'template_slug' => fake()->unique()->slug(2),
        ]);
    }

    public function locked(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_locked' => true,
        ]);
    }
}
