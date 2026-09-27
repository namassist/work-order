<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * Define the model's default state: an executor company.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('CO-###??'),
            'name' => fake()->unique()->company(),
            'is_client' => false,
            'email_domains' => [fake()->unique()->domainName()],
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the company is a client, which requests work orders.
     */
    public function client(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_client' => true,
        ]);
    }

    /**
     * Indicate that the company is deactivated.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
