<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDailyReport;
use App\Support\DisplayDate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A report dated today (display timezone) on a work order in Pelaksanaan,
 * with one link.
 *
 * @extends Factory<WorkOrderDailyReport>
 */
class WorkOrderDailyReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory()->inProgress(),
            'report_date' => DisplayDate::today(),
            'note' => fake()->sentence(),
            'links' => ['https://example.sharepoint.com/sites/wo/timesheet-'.fake()->numerify('###').'.xlsx'],
            'created_by' => User::factory(),
        ];
    }

    /**
     * Dated the given day (Y-m-d).
     */
    public function on(string $date): static
    {
        return $this->state(fn (array $attributes): array => ['report_date' => $date]);
    }
}
