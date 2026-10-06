<?php

namespace Database\Factories;

use App\Domain\Tenant\Models\Tenant;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->name(),
            'phone' => fake()->unique()->numerify('###########'),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
