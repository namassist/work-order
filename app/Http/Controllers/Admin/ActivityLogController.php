<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\AuditSubject;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Models\User;
use App\Support\DisplayDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    /**
     * Entries shown in a history panel; the full list is on the log page.
     */
    private const int HISTORY_LIMIT = 50;

    /**
     * List activity with filters and pagination, newest first.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Activity::class);

        $filters = $request->validate([
            'subject_type' => ['nullable', Rule::enum(AuditSubject::class)],
            'subject_id' => ['nullable', 'integer'],
            'causer' => ['nullable', 'integer'],
            'event' => ['nullable', Rule::enum(AuditEvent::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        // A record filter only means something together with its type.
        $filters['subject_id'] = isset($filters['subject_type']) ? ($filters['subject_id'] ?? null) : null;

        $activities = ActivityResource::query()
            ->when($filters['subject_type'] ?? null, fn (Builder $query, string $type) => $query->where('subject_type', $type))
            ->when($filters['subject_id'], fn (Builder $query, int $id) => $query->where('subject_id', $id))
            ->when($filters['causer'] ?? null, fn (Builder $query, int $id) => $query
                ->where('causer_type', AuditSubject::User->value)
                ->where('causer_id', $id))
            ->when($filters['event'] ?? null, fn (Builder $query, string $event) => $query->where('event', $event))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query
                ->where('created_at', '>=', DisplayDate::startOfDayUtc($from)))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query
                ->where('created_at', '<=', DisplayDate::endOfDayUtc($to)))
            ->paginate(15)
            ->withQueryString();

        $referenceCodes = ActivityResource::referenceCodes($activities->getCollection());

        return Inertia::render('admin/activity-log/Index', [
            'activities' => $activities->through(
                fn (Activity $activity): array => (new ActivityResource($activity, $referenceCodes))->resolve($request),
            ),
            'filters' => [
                'subject_type' => $filters['subject_type'] ?? '',
                'subject_id' => isset($filters['subject_id']) ? (string) $filters['subject_id'] : '',
                'causer' => isset($filters['causer']) ? (string) $filters['causer'] : '',
                'event' => $filters['event'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'subjectTypes' => AuditSubject::options(),
            'events' => AuditEvent::options(),
            'causers' => User::withTrashed()
                ->whereIn('id', Activity::query()->where('causer_type', AuditSubject::User->value)->select('causer_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
        ]);
    }

    /**
     * The latest activity of one record, for the history panel.
     */
    public function history(Request $request, string $subjectType, int $subjectId): JsonResponse
    {
        Gate::authorize('viewAny', Activity::class);

        $activities = ActivityResource::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->limit(self::HISTORY_LIMIT)
            ->get();

        $referenceCodes = ActivityResource::referenceCodes($activities);

        return response()->json([
            'data' => $activities
                ->map(fn (Activity $activity): array => (new ActivityResource($activity, $referenceCodes))->resolve($request))
                ->all(),
        ]);
    }
}
