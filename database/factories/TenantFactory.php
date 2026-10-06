<?php

namespace Database\Factories;

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'document' => fake()->unique()->numerify('##.###.###/0001-##'),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->numerify('(##) #####-####'),
            'is_active' => true,
            'trial_ends_at' => now()->addDays(30),
        ];
    }

    /**
     * Barbearia desativada: o usuário perde acesso mesmo com membership ativa.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
