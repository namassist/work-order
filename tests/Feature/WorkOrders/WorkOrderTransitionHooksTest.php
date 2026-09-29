<?php

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
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| The extension points of the later steps (FLOW.md §15): Pelaksanaan →
| Review Dokumen requires a daily report (step 4), Review Dokumen → Approval
| BAST generates the BAST (step 5). Both are PROVISIONAL placeholders that
| pass and do nothing; these tests pin where they are declared and how
| TransitionWorkOrder runs a requirement and an effect.
*/

it('declares the daily report requirement and the BAST effect on their transitions only', function () {
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
        'review_dokumen → approval_bast' => [[], [GenerateBast::class]],
    ]);
});

it('passes and does nothing until steps 4 and 5 fill them in', function () {
    $workOrder = WorkOrder::factory()->inProgress()->create();
    $action = app(TransitionWorkOrder::class);

    $action->handle($workOrder, 'review_dokumen', adminUser());
    $action->handle($workOrder, 'approval_bast', adminUser());

    expect($workOrder->refresh()->status->getValue())->toBe('approval_bast');
});

it('refuses the transition when a requirement is not met, leaving no trace', function () {
    app()->bind(EnsureDailyReport::class, fn (): TransitionRequirement => new class implements TransitionRequirement
    {
        public function ensureMet(WorkOrder $workOrder, User $user): void
        {
            throw ValidationException::withMessages(['status' => 'Belum ada laporan harian.']);
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

        public function ensureMet(WorkOrder $workOrder, User $user): void
        {
            $this->seen['status'] = $workOrder->status->getValue();
            $this->seen['level'] = DB::transactionLevel();
            $this->seen['user'] = $user->id;
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
    $workOrder = WorkOrder::factory()->inReview()->create();

    expect(fn () => app(TransitionWorkOrder::class)->handle($workOrder, 'approval_bast', adminUser()))
        ->toThrow(RuntimeException::class, 'Template BAST belum ada.');

    expect($workOrder->refresh()->status->getValue())->toBe('review_dokumen')
        ->and($workOrder->statusHistories()->exists())->toBeFalse()
        ->and(Activity::query()->forSubject($workOrder)->where('event', 'status_changed')->exists())->toBeFalse();
});
