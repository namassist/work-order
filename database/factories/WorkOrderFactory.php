<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Models\WorkOrderInvoice;
use App\States\WorkOrder\Diajukan;
use App\States\WorkOrder\Dibatalkan;
use App\States\WorkOrder\Dikerjakan;
use App\States\WorkOrder\Ditolak;
use App\States\WorkOrder\Draft;
use App\States\WorkOrder\Penagihan;
use App\States\WorkOrder\Selesai;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    /**
     * Define the model's default state: a draft without a target, entered by
     * a new executor company (Unggul) user for a contact of a new client
     * company (IC) department (FLOW.md v2 §4).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'requester_department_id' => Department::factory()->client(),
            'requester_name' => fake()->name(),
            'pic_name' => null,
            'target_department_id' => null,
            'work_order_category_id' => WorkOrderCategory::factory(),
            'created_by' => User::factory(),
            'status' => Draft::class,
            'target_date' => null,
        ];
    }

    /**
     * A work order entered by the given user (an Admin WO).
     */
    public function by(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'created_by' => $user->id,
        ]);
    }

    /**
     * A work order requested by the given (IC) department.
     */
    public function requestedBy(Department $department): static
    {
        return $this->state(fn (array $attributes): array => [
            'requester_department_id' => $department->id,
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
     * A submitted work order with a number (its target stays as set: it is
     * informational only).
     */
    public function submitted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Diajukan::class,
            'number' => fake()->unique()->numerify('WO/TEST/2026/09/####'),
        ]);
    }

    /**
     * A submitted work order Lead Operational rejected.
     */
    public function rejected(): static
    {
        return $this->submitted()->state(fn (array $attributes): array => [
            'status' => Ditolak::class,
        ]);
    }

    /**
     * A submitted work order being carried out.
     */
    public function inProgress(): static
    {
        return $this->submitted()->state(fn (array $attributes): array => [
            'status' => Dikerjakan::class,
        ]);
    }

    /**
     * An invoiced work order (Penagihan), with an
     * unpaid invoice (no files) issued by a new user unless the given
     * attributes say otherwise.
     *
     * @param  array<string, mixed>  $invoice
     */
    public function billed(array $invoice = []): static
    {
        return $this->submitted()
            ->state(fn (array $attributes): array => ['status' => Penagihan::class])
            ->afterCreating(fn (WorkOrder $workOrder) => WorkOrderInvoice::factory()->for($workOrder)->create($invoice));
    }

    /**
     * A work order whose invoice was paid (Selesai), confirmed by a new user.
     *
     * @param  array<string, mixed>  $invoice
     */
    public function paid(array $invoice = []): static
    {
        return $this->submitted()
            ->state(fn (array $attributes): array => ['status' => Selesai::class])
            ->afterCreating(fn (WorkOrder $workOrder) => WorkOrderInvoice::factory()->for($workOrder)->paid()->create($invoice));
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
