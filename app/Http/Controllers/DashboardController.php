<?php

namespace App\Http\Controllers;

use App\Enums\WorkOrderUrgency;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\WorkOrderResource;
use App\Models\User;
use App\Models\WorkOrder;
use App\States\WorkOrder\Diajukan;
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
            'department' => $user->department?->only(['code', 'name']),
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
     * Counts over the work orders the user may see. "Pending" means awaiting
     * approval, which in the provisional flow is the Diajukan status;
     * "overdue" is WorkOrder::overdue() ("Terlambat").
     *
     * @return array{total: int, pending: int, overdue: int}
     */
    private function workOrderCounts(User $user): array
    {
        $counts = WorkOrder::query()
            ->visibleTo($user)
            ->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(case when status = ? then 1 else 0 end), 0) as pending', [Diajukan::getMorphClass()])
            ->first();

        return [
            'total' => (int) $counts?->total,
            'pending' => (int) $counts?->pending,
            'overdue' => WorkOrder::query()->visibleTo($user)->overdue()->count(),
        ];
    }

    /**
     * Submitted "mendesak" work orders the user may see, waiting longest first.
     *
     * @return list<array{id: int, number: string|null, title: string, category: string, requester: array{id: int, name: string}, submitted_at: string|null}>
     */
    private function urgentWorkOrders(User $user): array
    {
        $workOrders = WorkOrder::query()
            ->visibleTo($user)
            ->where('status', Diajukan::getMorphClass())
            ->where('urgency', WorkOrderUrgency::Mendesak)
            ->withSubmittedAt()
            ->with(['category', 'requester'])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->limit(self::URGENT_LIMIT)
            ->get();

        return array_values($workOrders->map(fn (WorkOrder $workOrder): array => [
            'id' => $workOrder->id,
            'number' => $workOrder->number,
            'title' => $workOrder->title,
            'category' => $workOrder->category->name,
            'requester' => ['id' => $workOrder->requester->id, 'name' => $workOrder->requester->name],
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
            ->with(['department', 'category', 'requester'])
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
