<?php

namespace App\Http\Controllers;

use App\Enums\WorkOrderDeadline;
use App\Enums\WorkOrderUrgency;
use App\Http\Controllers\WorkOrders\WorkOrderController;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\WorkOrderResource;
use App\Models\User;
use App\Models\WorkOrder;
use App\States\WorkOrder\Diajukan;
use App\States\WorkOrder\Dikerjakan;
use App\States\WorkOrder\Penagihan;
use App\States\WorkOrder\WorkOrderStatus;
use App\Support\WorkOrderRequestOverview;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class DashboardController extends Controller
{
    /**
     * Entries in the "Aktivitas terbaru" panel; the full list is on the log page.
     */
    private const int RECENT_ACTIVITY_LIMIT = 8;

    /**
     * Entries in the "WO Mendesak" panel; "Lihat semua" opens the filtered list.
     */
    private const int URGENT_LIMIT = 5;

    /**
     * Entries in the "WO Terbaru" panel; "Lihat semua" opens the list sorted
     * by last activity.
     */
    private const int RECENT_WORK_ORDER_LIMIT = 8;

    /**
     * The dashboard: greeting, work order counts, charts, and the most
     * recently active work orders for users who may list work orders, and
     * recent activity for users who may read the activity log.
     */
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $canListWorkOrders = $user->can('viewAny', WorkOrder::class);
        $period = WorkOrderRequestOverview::period($request->query('period'));

        return Inertia::render('Dashboard', [
            'department' => $user->department->only(['code', 'name']),
            'workOrderCounts' => $canListWorkOrders
                ? Inertia::defer(fn (): array => $this->workOrderCounts($user))
                : null,
            'requestOverview' => $canListWorkOrders
                ? Inertia::defer(fn (): array => WorkOrderRequestOverview::for($user, $period))
                : null,
            'urgentWorkOrders' => $canListWorkOrders
                ? Inertia::defer(fn (): array => $this->urgentWorkOrders($user))
                : null,
            'recentWorkOrders' => $canListWorkOrders
                ? Inertia::defer(fn (): array => $this->recentWorkOrders($request, $user))
                : null,
            'recentActivities' => $user->can('viewAny', Activity::class)
                ? Inertia::defer(fn (): array => $this->recentActivities($request))
                : null,
        ]);
    }

    /**
     * The stat strip's counts over the work orders the user may see: one
     * per status the cards name, and "Terlambat" (WorkOrder::overdue())
     * with its split by the date the work order is late against.
     *
     * @return array{submitted: int, in_progress: int, billing: int, overdue: int, overdue_by: array<string, int>}
     */
    private function workOrderCounts(User $user): array
    {
        $counts = WorkOrder::query()
            ->visibleTo($user)
            ->toBase()
            ->selectRaw('count(*) filter (where status = ?) as submitted', [Diajukan::getMorphClass()])
            ->selectRaw('count(*) filter (where status = ?) as in_progress', [Dikerjakan::getMorphClass()])
            ->selectRaw('count(*) filter (where status = ?) as billing', [Penagihan::getMorphClass()])
            ->first();

        $overdueBy = [];

        foreach (WorkOrderDeadline::cases() as $deadline) {
            $overdueBy[$deadline->value] = WorkOrder::query()->visibleTo($user)->overdue($deadline)->count();
        }

        return [
            'submitted' => (int) $counts?->submitted,
            'in_progress' => (int) $counts?->in_progress,
            'billing' => (int) $counts?->billing,
            // Each status has at most one deadline, so the groups never overlap.
            'overdue' => array_sum($overdueBy),
            'overdue_by' => $overdueBy,
        ];
    }

    /**
     * Active "mendesak" work orders the user may see (the list filtered by
     * urgency mendesak and status aktif): the earliest in the flow first,
     * then waiting longest.
     *
     * @return list<array{id: int, number: string|null, title: string, category: string, requester_name: string, status: array{value: string, label: string, tone: string}, submitted_at: string|null}>
     */
    private function urgentWorkOrders(User $user): array
    {
        $workOrders = WorkOrder::query()
            ->visibleTo($user)
            ->whereIn('status', WorkOrderStatus::activeNames())
            ->where('urgency', WorkOrderUrgency::Mendesak)
            ->withSubmittedAt()
            ->with('category')
            ->orderByRaw('array_position(?::text[], status::text)', ['{'.implode(',', WorkOrderStatus::flowOrder()).'}'])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->limit(self::URGENT_LIMIT)
            ->get();

        return array_values($workOrders->map(fn (WorkOrder $workOrder): array => [
            'id' => $workOrder->id,
            'number' => $workOrder->number,
            'title' => $workOrder->title,
            'category' => $workOrder->category->name,
            'requester_name' => $workOrder->requester_name,
            'status' => $workOrder->status->toOption(),
            'submitted_at' => $this->isoMoment($workOrder->getAttribute('submitted_at')),
        ])->all());
    }

    /**
     * The work orders the user may see, most recently active first. Last
     * activity is updated_at, which comments, status changes, and
     * attachments also bump. Rows have the work order list's shape.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentWorkOrders(Request $request, User $user): array
    {
        $workOrders = WorkOrder::query()
            ->visibleTo($user)
            ->with(WorkOrderController::LIST_RELATIONS)
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::RECENT_WORK_ORDER_LIMIT)
            ->get();

        return WorkOrderResource::collection($workOrders)->resolve($request);
    }

    private function isoMoment(mixed $moment): ?string
    {
        return $moment instanceof CarbonInterface ? $moment->toIso8601String() : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentActivities(Request $request): array
    {
        $activities = ActivityResource::query()->limit(self::RECENT_ACTIVITY_LIMIT)->get();
        $referenceCodes = ActivityResource::referenceCodes($activities);

        return $activities
            ->map(fn (Activity $activity): array => new ActivityResource($activity, $referenceCodes)->resolve($request))
            ->all();
    }
}
