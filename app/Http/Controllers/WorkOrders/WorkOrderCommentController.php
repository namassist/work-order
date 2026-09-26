<?php

namespace App\Http\Controllers\WorkOrders;

use App\Actions\WorkOrders\AddWorkOrderComment;
use App\Actions\WorkOrders\CommentNotAllowed;
use App\Actions\WorkOrders\DeleteWorkOrderComment;
use App\Actions\WorkOrders\UpdateWorkOrderComment;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkOrders\StoreWorkOrderCommentRequest;
use App\Http\Requests\WorkOrders\UpdateWorkOrderCommentRequest;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderComment;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class WorkOrderCommentController extends Controller
{
    /**
     * Add a comment to the work order's timeline.
     */
    public function store(StoreWorkOrderCommentRequest $request, WorkOrder $workOrder, AddWorkOrderComment $addComment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(
            fn (): WorkOrderComment => $addComment->handle($workOrder, $user, $request->string('body')->toString()),
            __('Komentar ditambahkan.'),
        );
    }

    /**
     * Replace the text of the user's own comment.
     */
    public function update(UpdateWorkOrderCommentRequest $request, WorkOrder $workOrder, WorkOrderComment $comment, UpdateWorkOrderComment $updateComment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(
            fn (): WorkOrderComment => $updateComment->handle($comment, $user, $request->string('body')->toString()),
            __('Komentar diperbarui.'),
        );
    }

    /**
     * Delete the user's own comment; the timeline keeps a placeholder.
     */
    public function destroy(Request $request, WorkOrder $workOrder, WorkOrderComment $comment, DeleteWorkOrderComment $deleteComment): RedirectResponse
    {
        Gate::authorize('deleteComment', [$workOrder, $comment]);

        /** @var User $user */
        $user = $request->user();

        return $this->attempt(
            fn () => $deleteComment->handle($comment, $user),
            __('Komentar dihapus.'),
        );
    }

    /**
     * Run the change and flash its outcome. A rule the page could not know
     * about any more (the WO was cancelled, the edit window passed) comes back
     * as an error toast.
     */
    private function attempt(Closure $change, string $success): RedirectResponse
    {
        try {
            $change();
        } catch (CommentNotAllowed $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $success]);

        return back();
    }
}
