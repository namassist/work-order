<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Models\WorkOrderInvoice;
use App\States\WorkOrder\ApprovalBast;
use App\States\WorkOrder\BastDisetujui;
use App\States\WorkOrder\Closed;
use App\States\WorkOrder\Diajukan;
use App\States\WorkOrder\Dibatalkan;
use App\States\WorkOrder\Ditolak;
use App\States\WorkOrder\Draft;
use App\States\WorkOrder\Pelaksanaan;
use App\States\WorkOrder\ReviewDokumen;
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
     * A work order Lead Operational approved, being carried out (Pelaksanaan).
     */
    public function inProgress(): static
    {
        return $this->submitted()->state(fn (array $attributes): array => [
            'status' => Pelaksanaan::class,
        ]);
    }

    /**
     * A work order submitted for document review (Review Dokumen).
     */
    public function inReview(): static
    {
        return $this->submitted()->state(fn (array $attributes): array => [
            'status' => ReviewDokumen::class,
        ]);
    }

    /**
     * A work order whose BAST waits for the Direktur (Approval BAST).
     */
    public function awaitingBastApproval(): static
    {
        return $this->submitted()->state(fn (array $attributes): array => [
            'status' => ApprovalBast::class,
        ]);
    }

    /**
     * A work order whose BAST the Direktur approved (BAST Disetujui).
     */
    public function bastApproved(): static
    {
        return $this->submitted()->state(fn (array $attributes): array => [
            'status' => BastDisetujui::class,
        ]);
    }

    /**
     * A closed work order not billed yet (payment Belum ditagih).
     */
    public function closed(): static
    {
        return $this->submitted()->state(fn (array $attributes): array => [
            'status' => Closed::class,
        ]);
    }

    /**
     * A closed work order Finance billed (payment Ditagih), with an unpaid
     * invoice (no files) issued by a new user unless the given attributes
     * say otherwise.
     *
     * @param  array<string, mixed>  $invoice
     */
    public function billed(array $invoice = []): static
    {
        return $this->closed()
            ->afterCreating(fn (WorkOrder $workOrder) => WorkOrderInvoice::factory()->for($workOrder)->create($invoice));
    }

    /**
     * A closed work order whose invoice was paid (payment Lunas), confirmed
     * by a new user.
     *
     * @param  array<string, mixed>  $invoice
     */
    public function paid(array $invoice = []): static
    {
        return $this->closed()
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
