<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\States\WorkOrder\Diajukan;
use App\States\WorkOrder\Dibatalkan;
use App\States\WorkOrder\Draft;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'department_id' => Department::factory(),
            'work_order_category_id' => WorkOrderCategory::factory(),
            'created_by' => User::factory(),
            'status' => Draft::class,
            'target_date' => null,
        ];
    }

    /**
     * A work order created by the given user in their department.
     */
    public function by(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'created_by' => $user->id,
            'department_id' => $user->department_id ?? Department::factory(),
        ]);
    }

    /**
     * A submitted work order with a number.
     */
    public function submitted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Diajukan::class,
            'number' => fake()->unique()->numerify('WO/TEST/2026/09/####'),
        ]);
    }

    /**
     * A cancelled draft.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Dibatalkan::class,
        ]);
    }
}
