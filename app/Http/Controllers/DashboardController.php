<?php

namespace App\Http\Controllers;

use App\Http\Resources\ActivityResource;
use App\Models\User;
use App\Models\WorkOrder;
use App\States\WorkOrder\Diajukan;
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
     * The dashboard: greeting, work order counts for users who may list work
     * orders, and recent activity for users who may read the activity log.
     */
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'department' => $user->department?->only(['code', 'name']),
            'workOrderCounts' => $user->can('viewAny', WorkOrder::class)
                ? Inertia::defer(fn (): array => $this->workOrderCounts($user))
                : null,
            'recentActivities' => $user->can('viewAny', Activity::class)
                ? Inertia::defer(fn (): array => $this->recentActivities($request))
                : null,
        ]);
    }

    /**
     * Counts over the work orders the user may see. "Pending" means awaiting
     * approval, which in the provisional flow is the Diajukan status.
     *
     * @return array{total: int, pending: int}
     */
    private function workOrderCounts(User $user): array
    {
        $counts = WorkOrder::query()
            ->visibleTo($user)
            ->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(case when status = ? then 1 else 0 end), 0) as pending', [Diajukan::getMorphClass()])
            ->first();

        return ['total' => (int) $counts?->total, 'pending' => (int) $counts?->pending];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentActivities(Request $request): array
    {
        $activities = ActivityResource::query()->limit(self::RECENT_ACTIVITY_LIMIT)->get();
        $referenceCodes = ActivityResource::referenceCodes($activities);

        return $activities
            ->map(fn (Activity $activity): array => (new ActivityResource($activity, $referenceCodes))->resolve($request))
            ->all();
    }
}
