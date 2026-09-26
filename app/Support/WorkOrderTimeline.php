<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderComment;
use App\Models\WorkOrderStatusHistory;

/**
 * A work order's status changes and comments as one chronological list for
 * the detail page. Transition notes stay part of their status entry; deleted
 * comments keep their place without their text.
 */
class WorkOrderTimeline
{
    /**
     * Entries oldest first. At the same moment a status change comes before
     * a comment, then each kind keeps its id order.
     *
     * @return list<array<string, mixed>>
     */
    public static function for(WorkOrder $workOrder, User $viewer): array
    {
        $workOrder->loadMissing([
            'statusHistories.user',
            'comments' => fn ($query) => $query->withTrashed()->with('author'),
        ]);

        $acceptsComments = $workOrder->status->acceptsComments();

        $entries = [
            ...$workOrder->statusHistories->map(fn (WorkOrderStatusHistory $history): array => [
                'sort' => [$history->created_at->getTimestamp(), $history->created_at->micro, 0, $history->id],
                'entry' => ['type' => 'status', ...$history->toTimelineEntry()],
            ]),
            ...$workOrder->comments->map(fn (WorkOrderComment $comment): array => [
                'sort' => [$comment->created_at->getTimestamp(), $comment->created_at->micro, 1, $comment->id],
                'entry' => self::commentEntry($workOrder, $comment, $viewer, $acceptsComments),
            ]),
        ];

        usort($entries, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_column($entries, 'entry');
    }

    /**
     * @return array{type: 'comment', id: int, user: array{id: int, name: string}, body: string|null, deleted: bool, edited: bool, created_at: string, can: array{update: bool, delete: bool}}
     */
    private static function commentEntry(WorkOrder $workOrder, WorkOrderComment $comment, User $viewer, bool $acceptsComments): array
    {
        $deleted = $comment->trashed();
        $changeable = ! $deleted && $acceptsComments && $comment->isWithinEditWindow();

        return [
            'type' => 'comment',
            'id' => $comment->id,
            'user' => ['id' => $comment->author->id, 'name' => $comment->author->name],
            'body' => $deleted ? null : $comment->body,
            'deleted' => $deleted,
            'edited' => $comment->edited_at !== null,
            'created_at' => $comment->created_at->toIso8601String(),
            'can' => [
                'update' => $changeable && $viewer->can('updateComment', [$workOrder, $comment]),
                'delete' => $changeable && $viewer->can('deleteComment', [$workOrder, $comment]),
            ],
        ];
    }
}
