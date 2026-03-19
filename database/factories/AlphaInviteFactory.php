<?php

namespace Database\Factories;

use App\Models\AlphaInvite;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AlphaInviteFactory extends Factory
{
    protected $model = AlphaInvite::class;

    public function definition(): array
    {
        $token = Str::random(64);

        return [
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', $token),
            'last_sent_at' => now(),
        ];
    }

    public function registered(): static
    {
        return $this->state(fn (array $attributes) => [
            'registered_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }

    public function withToken(string $token): static
    {
        return $this->state(fn (array $attributes) => [
            'token_hash' => hash('sha256', $token),
        ]);
    }
}
