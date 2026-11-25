<?php

namespace Database\Factories;

use App\Modules\Admin\Models\Admin;
use App\Modules\Admin\Support\AdminModules;
use App\Modules\Fulfilment\Support\Branches;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Admin> */
class AdminFactory extends Factory
{
    protected $model = Admin::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'password' => 'password',
            'is_super' => false,
            'modules' => AdminModules::ALL,
            'branches' => Branches::codes(),
            'must_change_password' => false,
        ];
    }

    public function super(): static
    {
        return $this->state(fn () => ['is_super' => true, 'modules' => null, 'branches' => null]);
    }

    /** @param list<string> $modules @param list<string> $branches */
    public function limited(array $modules, array $branches = []): static
    {
        return $this->state(fn () => ['modules' => $modules, 'branches' => $branches]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['disabled_at' => now()]);
    }
}
