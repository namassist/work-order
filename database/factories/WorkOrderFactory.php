<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\States\WorkOrder\Diajukan;
use App\States\WorkOrder\Dibatalkan;
use App\States\WorkOrder\Dikerjakan;
use App\States\WorkOrder\Ditolak;
use App\States\WorkOrder\Draft;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    /**
     * Define the model's default state: a draft without a target, entered by
     * its requester in a new client company (IC) department.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'requester_department_id' => Department::factory()->client(),
            'target_department_id' => null,
            'work_order_category_id' => WorkOrderCategory::factory(),
            'created_by' => fn (array $attributes): int => User::factory()->create(['department_id' => $attributes['requester_department_id']])->id,
            'requester_id' => fn (array $attributes): int => $attributes['created_by'],
            'requester_name' => null,
            'status' => Draft::class,
            'target_date' => null,
        ];
    }

    /**
     * A work order entered by the given user for their own department.
     */
    public function by(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'created_by' => $user->id,
            'requester_id' => $user->id,
            'requester_name' => null,
            'requester_department_id' => $user->department_id,
        ]);
    }

    /**
     * A work order entered by a koordinator on behalf of an IC account, or
     * of someone without one (a contact name).
     */
    public function onBehalf(User $enteredBy, ?User $account = null, string $contactName = 'Pak Andi'): static
    {
        return $this->state(fn (array $attributes): array => [
            'created_by' => $enteredBy->id,
            'requester_id' => $account?->id,
            'requester_name' => $account instanceof User ? null : $contactName,
            'requester_department_id' => $account->department_id ?? $attributes['requester_department_id'],
        ]);
    }

    /**
     * A work order addressed to the given department.
     */
    public function targeting(Department $department): static
    {
        return $this->state(fn (array $attributes): array => [
            'target_department_id' => $department->id,
        ]);
    }

    /**
     * A submitted work order with a number, addressed to a new executor
     * department unless a target is already set.
     */
    public function submitted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Diajukan::class,
            'number' => fake()->unique()->numerify('WO/TEST/2026/09/####'),
            'target_department_id' => $attributes['target_department_id'] ?? Department::factory(),
        ]);
    }

    /**
     * A submitted work order its target department rejected.
     */
    public function rejected(): static
    {
        return $this->submitted()->state(fn (array $attributes): array => [
            'status' => Ditolak::class,
        ]);
    }

    /**
     * A submitted work order its target department is carrying out.
     */
    public function inProgress(): static
    {
        return $this->submitted()->state(fn (array $attributes): array => [
            'status' => Dikerjakan::class,
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
