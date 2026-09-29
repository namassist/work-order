<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrderInvoice>
 */
class WorkOrderInvoiceFactory extends Factory
{
    /**
     * Define the model's default state: an unpaid invoice without an amount
     * or due date, issued by a new user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory()->closed(),
            'number' => fake()->unique()->numerify('INV/TEST/2026/####'),
            'invoice_date' => '2026-09-20',
            'amount' => null,
            'due_date' => null,
            'issued_by' => User::factory(),
        ];
    }

    /**
     * An invoice paid on the given date, confirmed by a new user unless one is given.
     */
    public function paid(string $on = '2026-09-24', ?User $by = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'paid_on' => $on,
            'paid_by' => $by->id ?? User::factory(),
        ]);
    }
}
