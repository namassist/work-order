<?php

namespace Database\Factories;

use App\Models\WorkOrderCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrderCategory>
 */
class WorkOrderCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('CAT-###??'),
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the category is deactivated.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
