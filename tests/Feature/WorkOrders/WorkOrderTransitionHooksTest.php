<?php

use App\Actions\WorkOrders\Transitions\ApproveBast;
use App\Actions\WorkOrders\Transitions\EnsureActiveBastTemplate;
use App\Actions\WorkOrders\Transitions\EnsureDailyReport;
use App\Actions\WorkOrders\Transitions\GenerateBast;
use App\Actions\WorkOrders\Transitions\TransitionEffect;
use App\Actions\WorkOrders\Transitions\TransitionRequirement;
use App\Actions\WorkOrders\TransitionWorkOrder;
use App\Models\User;
use App\Models\WorkOrder;
use App\States\WorkOrder\WorkOrderStatus;
use App\States\WorkOrder\WorkOrderTransition;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
| The extension points of the flow (FLOW.md §15): Pelaksanaan → Review
| Dokumen requires a daily report (step 4, DailyReportRequirementTest),
| Review Dokumen → Approval BAST requires an active BAST template and
| generates the BAST, Approval BAST → BAST Disetujui generates its final PDF
| (step 5, BastGenerationTest). These tests pin where they are declared and
| how TransitionWorkOrder runs a requirement and an effect.
*/

it('declares the daily report and BAST requirements and the BAST effects on their transitions only', function () {
    $hooks = [];

    foreach (WorkOrderStatus::flowOrder() as $from) {
        foreach (WorkOrderStatus::fromName($from)::transitions() as $transition) {
            /** @var WorkOrderTransition $transition */
            if ($transition->requirements !== [] || $transition->effects !== []) {
                $hooks["{$from} → {$transition->toName()}"] = [$transition->requirements, $transition->effects];
            }
        }
    }

    expect($hooks)->toBe([
        'pelaksanaan → review_dokumen' => [[EnsureDailyReport::class], []],
        'review_dokumen → approval_bast' => [[EnsureActiveBastTemplate::class], [GenerateBast::class]],
        'approval_bast → bast_disetujui' => [[], [ApproveBast::class]],
    ]);
});

it('refuses the transition when a requirement is not met, leaving no trace', function () {
    app()->bind(EnsureDailyReport::class, fn (): TransitionRequirement => new class implements TransitionRequirement
    {
        public function unmetReason(WorkOrder $workOrder, User $user): string
        {
            return 'Belum ada laporan harian.';
        }
    });
    $workOrder = WorkOrder::factory()->inProgress()->create();

    $this->actingAs(adminUser())
        ->post(route('work-orders.transitions.store', $workOrder), ['status' => 'review_dokumen'])
        ->assertSessionHasErrors(['status' => 'Belum ada laporan harian.']);

    expect($workOrder->refresh()->status->getValue())->toBe('pelaksanaan')
        ->and($workOrder->statusHistories()->where('to_status', 'review_dokumen')->exists())->toBeFalse()
        ->and(Activity::query()->forSubject($workOrder)->where('event', 'status_changed')->exists())->toBeFalse();
});

it('checks a requirement with the work order locked, before the status changes', function () {
    $seen = new ArrayObject;
    app()->bind(EnsureDailyReport::class, fn (): TransitionRequirement => new class($seen) implements TransitionRequirement
    {
        public function __construct(private ArrayObject $seen) {}

        public function unmetReason(WorkOrder $workOrder, User $user): ?string
        {
            $this->seen['status'] = $workOrder->status->getValue();
            $this->seen['level'] = DB::transactionLevel();
            $this->seen['user'] = $user->id;

            return null;
        }
    });
    $user = adminUser();

    app(TransitionWorkOrder::class)->handle(WorkOrder::factory()->inProgress()->create(), 'review_dokumen', $user);

    // RefreshDatabase wraps each test in a transaction, so the action's own is level 2.
    expect($seen->getArrayCopy())->toBe(['status' => 'pelaksanaan', 'level' => 2, 'user' => $user->id]);
});

it('runs an effect once, after the status changed, in the same transaction', function () {
    $seen = new ArrayObject;
    app()->bind(GenerateBast::class, fn (): TransitionEffect => new class($seen) implements TransitionEffect
    {
        public function __construct(private ArrayObject $seen) {}

        public function handle(WorkOrder $workOrder, User $user): void
        {
            $this->seen[] = [$workOrder->status->getValue(), $workOrder->statusHistories()->where('to_status', 'approval_bast')->count(), DB::transactionLevel()];
        }
    });

    activeBastTemplate();
    app(TransitionWorkOrder::class)->handle(WorkOrder::factory()->inReview()->create(), 'approval_bast', adminUser());

    expect($seen->getArrayCopy())->toBe([['approval_bast', 1, 2]]);
});

it('rolls the status change back when an effect fails', function () {
    app()->bind(GenerateBast::class, fn (): TransitionEffect => new class implements TransitionEffect
    {
        public function handle(WorkOrder $workOrder, User $user): void
        {
            throw new RuntimeException('Template BAST belum ada.');
        }
    });
    activeBastTemplate();
    $workOrder = WorkOrder::factory()->inReview()->create();

    expect(fn () => app(TransitionWorkOrder::class)->handle($workOrder, 'approval_bast', adminUser()))
        ->toThrow(RuntimeException::class, 'Template BAST belum ada.');

    expect($workOrder->refresh()->status->getValue())->toBe('review_dokumen')
        ->and($workOrder->statusHistories()->exists())->toBeFalse()
        ->and(Activity::query()->forSubject($workOrder)->where('event', 'status_changed')->exists())->toBeFalse();
});
