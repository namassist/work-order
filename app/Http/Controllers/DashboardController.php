<?php

namespace App\Http\Controllers;

use App\Http\Resources\ActivityResource;
use App\Models\User;
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
     * The dashboard: greeting, metric placeholders, and recent activity for
     * users who may read the activity log.
     */
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'department' => $user->department?->only(['code', 'name']),
            'recentActivities' => $user->can('viewAny', Activity::class)
                ? Inertia::defer(fn (): array => $this->recentActivities($request))
                : null,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentActivities(Request $request): array
    {
        $activities = ActivityResource::query()->limit(self::RECENT_ACTIVITY_LIMIT)->get();
        $departmentCodes = ActivityResource::departmentCodes($activities);

        return $activities
            ->map(fn (Activity $activity): array => (new ActivityResource($activity, $departmentCodes))->resolve($request))
            ->all();
    }
}
