<?php

namespace App\Support;

use App\Http\Resources\AttachmentResource;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderComment;
use App\Models\WorkOrderStatusHistory;
use App\Support\Comments\CommentHtml;
use Illuminate\Http\Request;

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
    public static function for(WorkOrder $workOrder, User $viewer, Request $request): array
    {
        $workOrder->loadMissing([
            'statusHistories.user',
            'comments' => fn ($query) => $query->withTrashed()->with(['author', 'documents.uploader']),
        ]);

        $acceptsComments = $workOrder->status->acceptsComments();

        $entries = [
            ...$workOrder->statusHistories->map(fn (WorkOrderStatusHistory $history): array => [
                'sort' => [$history->created_at->getTimestamp(), $history->created_at->micro, 0, $history->id],
                'entry' => [
                    'type' => 'status',
                    ...$history->toTimelineEntry(),
                    // "Diinput oleh X atas nama Y" on the creation entry.
                    'on_behalf_of' => $history->from_status === null ? $workOrder->requester_name : null,
                ],
            ]),
            ...$workOrder->comments->map(fn (WorkOrderComment $comment): array => [
                'sort' => [$comment->created_at->getTimestamp(), $comment->created_at->micro, 1, $comment->id],
                'entry' => self::commentEntry($workOrder, $comment, $viewer, $acceptsComments, $request),
            ]),
        ];

        usort($entries, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_column($entries, 'entry');
    }

    /**
     * The body is sanitized again before it is sent (CommentHtml::forDisplay)
     * and rendered as HTML; a deleted comment has neither body nor files.
     *
     * @return array{type: 'comment', id: int, user: array{id: int, name: string}, body: string|null, attachments: list<array<string, mixed>>, deleted: bool, edited: bool, created_at: string, can: array{update: bool, delete: bool}}
     */
    private static function commentEntry(WorkOrder $workOrder, WorkOrderComment $comment, User $viewer, bool $acceptsComments, Request $request): array
    {
        $deleted = $comment->trashed();
        $changeable = ! $deleted && $acceptsComments && $comment->isWithinEditWindow();

        return [
            'type' => 'comment',
            'id' => $comment->id,
            'user' => ['id' => $comment->author->id, 'name' => $comment->author->name],
            'body' => $deleted ? null : CommentHtml::forDisplay($comment->body),
            'attachments' => $deleted ? [] : array_values($comment->documents
                ->map(fn (Media $media): array => new AttachmentResource($media)->resolve($request))
                ->all()),
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
