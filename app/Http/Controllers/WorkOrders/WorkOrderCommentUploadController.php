<?php

namespace App\Http\Controllers\WorkOrders;

use App\Actions\Attachments\AddAttachment;
use App\Actions\WorkOrders\CommentNotAllowed;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResource;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Files for a comment that is still being written: an inline image or a
 * document, kept as the uploader's pending upload on the work order until
 * a comment claims it (CommentContent) or it is pruned
 * (work-orders:prune-comment-uploads). Answers JSON for the editor.
 */
class WorkOrderCommentUploadController extends Controller
{
    /**
     * Upload one file of the kind ('gambar' or 'lampiran').
     */
    public function store(Request $request, WorkOrder $workOrder, string $kind, AddAttachment $addAttachment): JsonResponse
    {
        // Keeps the policy's 404 for work orders the user cannot see.
        Gate::authorize('addComment', $workOrder);

        $rules = $workOrder->commentUploadCollectionFor($kind) ?? abort(404);

        if (! $workOrder->status->acceptsComments()) {
            throw ValidationException::withMessages(['file' => CommentNotAllowed::readOnly($workOrder)->getMessage()]);
        }

        $request->validate(['file' => $rules->fileRules()]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        /** @var User $user */
        $user = $request->user();

        $media = $addAttachment->handle($workOrder, $rules, $file, $user);

        return response()->json([
            ...new AttachmentResource($media)->resolve($request),
            'url' => route('attachments.show', $media->uuid, false),
        ], 201);
    }
}
