<?php

namespace App\Support\Attachments;

use App\Http\Resources\AttachmentResource;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Props for the AttachmentPanel component: where to upload, the collection's
 * rules, its files, and what the user may do.
 */
class AttachmentPanel
{
    /**
     * @return array{target: array{type: string, id: int|string, collection: string}, rules: array<string, mixed>, items: list<array<string, mixed>>, can: array{upload: bool, delete: bool}}
     */
    public static function props(Model&Attachable $parent, string $collection, User $user, Request $request): array
    {
        $rules = $parent->attachmentCollection($collection) ?? throw new InvalidArgumentException("Unknown attachment collection [{$collection}].");

        /** @var int|string $id */
        $id = $parent->getKey();

        $items = array_values(array_map(
            // Removal may depend on the file, e.g. only one's own uploads.
            fn (Media $media): array => [
                ...new AttachmentResource($media)->resolve($request),
                'can_delete' => $user->can('deleteAttachment', [$parent, $media]),
            ],
            $parent->attachmentsIn($rules->name)->all(),
        ));

        return [
            'target' => ['type' => $parent->getMorphClass(), 'id' => $id, 'collection' => $rules->name],
            'rules' => $rules->toFrontend(),
            'items' => $items,
            'can' => [
                'upload' => $user->can('addAttachment', [$parent, $rules->name]),
                'delete' => in_array(true, array_column($items, 'can_delete'), true),
            ],
        ];
    }
}
