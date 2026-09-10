<?php

namespace Database\Factories;

use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '91'.fake()->unique()->numerify('9#########'),
            'avatar' => null,
            'firebase_uid' => null,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'password' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
            'phone_verified_at' => null,
        ]);
    }

    public function incompleteProfile(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => null,
        ]);
    }
}
