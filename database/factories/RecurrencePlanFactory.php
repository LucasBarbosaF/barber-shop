<?php

namespace Database\Factories;

use App\Domain\Tenant\Models\Tenant;
use App\Models\RecurrencePlan;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurrencePlan>
 */
class RecurrencePlanFactory extends Factory
{
    protected $model = RecurrencePlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'service_id' => fn (array $attributes) => Service::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
            ])->getKey(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'duration_months' => 6,
            'sessions_per_month' => 2,
            'max_sessions_per_week' => 2,
            'price' => fake()->randomFloat(2, 50, 400),
            'is_active' => true,
        ];
    }
}
