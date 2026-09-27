<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * Define the model's default state: a department of a new executor company.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => fake()->unique()->bothify('DEP-###??'),
            'name' => fake()->unique()->company(),
            'is_active' => true,
        ];
    }

    /**
     * A department of a new client company (IC side).
     */
    public function client(): static
    {
        return $this->state(fn (array $attributes): array => [
            'company_id' => Company::factory()->client(),
        ]);
    }

    /**
     * Indicate that the department is deactivated.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
