<?php

namespace Database\Factories;

use App\Domain\Tenant\Models\Tenant;
use App\Models\Barber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Barber>
 */
class BarberFactory extends Factory
{
    protected $model = Barber::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->name(),
            'phone' => fake()->unique()->numerify('###########'),
            'email' => fake()->optional()->safeEmail(),
            'bio' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
