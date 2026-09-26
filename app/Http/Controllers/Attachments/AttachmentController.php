<?php

namespace App\Http\Controllers\Attachments;

use App\Actions\Attachments\AddAttachment;
use App\Actions\Attachments\RemoveAttachment;
use App\Enums\AttachmentType;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\User;
use App\Support\Attachments\Attachable;
use App\Support\Attachments\AttachmentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Attachments of any Attachable model. Every request is authorized against
 * the parent's policy: view to read, addAttachment/deleteAttachment to
 * change. Files are only ever served from here, never by URL on the disk.
 */
class AttachmentController extends Controller
{
    /**
     * Upload one file to a collection of the parent.
     */
    public function store(Request $request, string $attachableType, string $attachableId, string $collection, AddAttachment $addAttachment): RedirectResponse
    {
        $parent = $this->findParent($attachableType, $attachableId);
        $rules = $parent->attachmentCollection($collection) ?? abort(404);

        Gate::authorize('addAttachment', [$parent, $rules->name]);

        $request->validate(['file' => $rules->fileRules()]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        /** @var User $user */
        $user = $request->user();

        $media = $addAttachment->handle($parent, $rules, $file, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Lampiran :name diunggah.', ['name' => $media->name])]);

        return back();
    }

    /**
     * Send the file: inline for images and PDF (unless ?download=1),
     * otherwise as a download.
     */
    public function show(Request $request, Media $media): StreamedResponse
    {
        Gate::authorize('view', $this->parentOf($media));

        $type = AttachmentType::tryFrom((string) $media->mime_type);
        $inline = $type?->isPreviewable() === true && ! $request->boolean('download');

        return Storage::disk($media->disk)->response(
            $media->getPathRelativeToRoot(),
            $this->downloadName($media, $type),
            [
                'Content-Type' => $type->value ?? 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
            $inline ? 'inline' : 'attachment',
        );
    }

    /**
     * Delete the attachment and its file.
     */
    public function destroy(Media $media, RemoveAttachment $removeAttachment): RedirectResponse
    {
        $parent = $this->parentOf($media);

        Gate::authorize('deleteAttachment', [$parent, $media]);

        $removeAttachment->handle($parent, $media);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Lampiran :name dihapus.', ['name' => $media->name])]);

        return back();
    }

    /**
     * The parent named by its morph alias; 404 unless it takes attachments.
     */
    private function findParent(string $alias, string $id): Model&Attachable
    {
        $class = Relation::getMorphedModel($alias);

        if ($class === null || ! is_subclass_of($class, Attachable::class)) {
            abort(404);
        }

        return $class::query()->findOrFail($id);
    }

    /**
     * The attachment's parent; 404 if it was deleted or no longer has the
     * collection, so orphaned files are never served.
     */
    private function parentOf(Media $media): Model&Attachable
    {
        $parent = $media->model;

        if (! $parent instanceof Attachable || ! $parent->attachmentCollection($media->collection_name) instanceof AttachmentCollection) {
            abort(404);
        }

        return $parent;
    }

    /**
     * The original name, with the detected type's extension appended when
     * the name does not already end in it.
     */
    private function downloadName(Media $media, ?AttachmentType $type): string
    {
        $extension = mb_strtolower(pathinfo($media->name, PATHINFO_EXTENSION));

        if (! $type instanceof AttachmentType || in_array($extension, $type->knownExtensions(), true)) {
            return $media->name;
        }

        return $media->name.'.'.$type->extension();
    }
}
