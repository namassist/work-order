<?php

namespace App\Support\Comments;

use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderComment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * A comment's body and files as submitted, checked against the files the
 * author may use: their own pending uploads on the work order, plus the
 * files already on the comment being edited. Nothing else can appear in
 * a comment, so another work order's (or another user's) upload is never
 * shown or claimed. Resolve it with the work order locked.
 */
final readonly class CommentContent
{
    /**
     * @param  list<Media>  $images  in body order
     * @param  list<Media>  $documents  in submitted order
     * @param  list<Media>  $previousFiles  the comment's files before the change
     */
    private function __construct(
        public SanitizedComment $body,
        public array $images,
        public array $documents,
        public array $previousFiles,
    ) {}

    /**
     * @param  list<string>  $documentUuids
     *
     * @throws ValidationException when the body is empty, too large, or too long, or a file is not the author's or over the limit
     */
    public static function resolve(WorkOrder $workOrder, ?WorkOrderComment $comment, User $author, string $html, array $documentUuids): self
    {
        if (strlen($html) > CommentHtml::MAX_HTML_BYTES) {
            throw ValidationException::withMessages(['body' => __('Komentar terlalu besar. Kurangi format atau pecah menjadi beberapa komentar.')]);
        }

        $usable = self::usableFiles($workOrder, $comment, $author);
        $body = CommentHtml::sanitize($html, fn (string $uuid): ?string => self::isImage($usable->get($uuid)) ? $usable->get($uuid)?->name : null);
        $documents = self::documents($usable, $documentUuids);

        if (! $body->hasContent() && $documents === []) {
            throw ValidationException::withMessages(['body' => __('Komentar tidak boleh kosong.')]);
        }

        if (mb_strlen($body->text) > CommentHtml::MAX_TEXT_LENGTH) {
            throw ValidationException::withMessages(['body' => __('Komentar tidak boleh lebih dari :max karakter.', ['max' => CommentHtml::MAX_TEXT_LENGTH])]);
        }

        $maxImages = config()->integer('work_order.comments.images.max_files');

        // Repeats count too: one upload shown a thousand times is a thousand images.
        if ($body->imageCount > $maxImages) {
            throw ValidationException::withMessages(['body' => __('Komentar berisi paling banyak :max gambar.', ['max' => $maxImages])]);
        }

        $images = array_map(fn (string $uuid): Media => $usable->get($uuid) ?? throw new LogicException("Unresolved image [{$uuid}]."), $body->imageUuids);
        $previous = $comment instanceof WorkOrderComment
            ? [...$comment->attachmentsIn(WorkOrderComment::IMAGES)->all(), ...$comment->attachmentsIn(WorkOrderComment::DOCUMENTS)->all()]
            : [];

        return new self($body, $images, $documents, $previous);
    }

    /**
     * Whether the comment's files differ from before (not their order).
     */
    public function changesFiles(): bool
    {
        $uuids = fn (array $files): array => collect($files)->pluck('uuid')->sort()->values()->all();

        return $uuids([...$this->images, ...$this->documents]) !== $uuids($this->previousFiles);
    }

    /**
     * Names of the files before and after, for the audit log: images, then
     * documents.
     *
     * @return array{old: list<string>, new: list<string>}
     */
    public function fileNames(): array
    {
        $names = fn (array $files): array => array_values(array_map(fn (Media $media): string => $media->name, $files));

        return ['old' => $names($this->previousFiles), 'new' => $names([...$this->images, ...$this->documents])];
    }

    /**
     * Move the pending uploads onto the (saved) comment, and delete the
     * comment's files it no longer shows once the transaction commits:
     * deleted files cannot be rolled back.
     */
    public function claimFor(WorkOrderComment $comment): void
    {
        foreach ([WorkOrderComment::IMAGES => $this->images, WorkOrderComment::DOCUMENTS => $this->documents] as $collection => $files) {
            foreach ($files as $media) {
                if ($media->model_type === $comment->getMorphClass() && (int) $media->model_id === $comment->id) {
                    continue;
                }

                $media->forceFill([
                    'model_type' => $comment->getMorphClass(),
                    'model_id' => $comment->id,
                    'collection_name' => $collection,
                ])->save();
            }
        }

        $kept = collect([...$this->images, ...$this->documents])->pluck('uuid')->all();
        self::deleteAfterCommit(array_values(array_filter($this->previousFiles, fn (Media $media): bool => ! in_array($media->uuid, $kept, true))));
    }

    /**
     * Delete files (and their stored copies) once the transaction commits.
     *
     * @param  list<Media>  $files
     */
    public static function deleteAfterCommit(array $files): void
    {
        if ($files === []) {
            return;
        }

        DB::afterCommit(function () use ($files): void {
            foreach ($files as $media) {
                $media->delete();
            }
        });
    }

    /**
     * The author's pending uploads on the work order and the comment's own
     * files, keyed by uuid.
     *
     * @return Collection<string, Media>
     */
    private static function usableFiles(WorkOrder $workOrder, ?WorkOrderComment $comment, User $author): Collection
    {
        return Media::query()
            ->where(fn (Builder $pending) => $pending
                ->whereMorphedTo('model', $workOrder)
                ->whereIn('collection_name', [WorkOrder::COMMENT_IMAGE_UPLOADS, WorkOrder::COMMENT_FILE_UPLOADS])
                ->where('uploaded_by', $author->id))
            ->when($comment, fn (Builder $query, WorkOrderComment $comment) => $query->orWhere(fn (Builder $own) => $own->whereMorphedTo('model', $comment)))
            ->get()
            ->keyBy('uuid');
    }

    /**
     * @param  Collection<string, Media>  $usable
     * @param  list<string>  $uuids
     * @return list<Media>
     *
     * @throws ValidationException
     */
    private static function documents(Collection $usable, array $uuids): array
    {
        $documents = [];

        foreach (array_unique(array_map(mb_strtolower(...), $uuids)) as $uuid) {
            $media = $usable->get($uuid);

            if (! $media instanceof Media || ! in_array($media->collection_name, [WorkOrder::COMMENT_FILE_UPLOADS, WorkOrderComment::DOCUMENTS], true)) {
                throw ValidationException::withMessages(['attachments' => __('Lampiran tidak ditemukan atau sudah kedaluwarsa. Unggah ulang berkasnya.')]);
            }

            $documents[] = $media;
        }

        $max = config()->integer('work_order.comments.documents.max_files');

        if (count($documents) > $max) {
            throw ValidationException::withMessages(['attachments' => __('Komentar berisi paling banyak :max lampiran.', ['max' => $max])]);
        }

        return $documents;
    }

    private static function isImage(?Media $media): bool
    {
        return $media instanceof Media && in_array($media->collection_name, [WorkOrder::COMMENT_IMAGE_UPLOADS, WorkOrderComment::IMAGES], true);
    }
}
