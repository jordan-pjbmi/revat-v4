<?php

namespace Database\Factories;

use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WaitlistEntryFactory extends Factory
{
    protected $model = WaitlistEntry::class;

    public function definition(): array
    {
        $token = Str::random(64);

        return [
            'email' => fake()->unique()->safeEmail(),
            'verification_token_hash' => hash('sha256', $token),
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_token_hash' => null,
            'verified_at' => now(),
        ]);
    }

    public function withToken(string $token): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_token_hash' => hash('sha256', $token),
        ]);
    }
}
