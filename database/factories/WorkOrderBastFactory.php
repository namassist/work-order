<?php

namespace Database\Factories;

use App\Models\BastTemplateVersion;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderBast;
use App\States\WorkOrder\ApprovalBast;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A BAST submitted by a new user, generated from a new template version,
 * without files; not approved unless ->approved().
 *
 * @extends Factory<WorkOrderBast>
 */
class WorkOrderBastFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrder::factory()->submitted()->state(['status' => ApprovalBast::class]),
            'number' => 'BAST/'.fake()->unique()->numerify('####/##/######'),
            'bast_template_version_id' => BastTemplateVersion::factory(),
            'submitted_by' => User::factory(),
            'submitted_at' => now(),
        ];
    }

    /**
     * Approved by a new user, with the SHA-256 of a final PDF that is not stored.
     */
    public function approved(): static
    {
        return $this->state(function (array $attributes): array {
            $approver = User::factory()->create();

            return [
                'approved_by' => $approver->id,
                'approver_name' => $approver->name,
                'approved_at' => now(),
                'final_sha256' => hash('sha256', fake()->uuid()),
            ];
        });
    }
}
