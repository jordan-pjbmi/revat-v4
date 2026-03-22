<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserDashboardPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserDashboardPreference>
 */
class UserDashboardPreferenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => 1,
            'active_dashboard_id' => null,
        ];
    }
}
