<?php

namespace App\Http\Resources;

use App\Enums\AttachmentType;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An attachment as the AttachmentPanel lists it. The id is the media UUID;
 * file URLs are built on the client with Wayfinder.
 *
 * @property Media $resource
 */
class AttachmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = $this->resource;
        $type = AttachmentType::tryFrom((string) $media->mime_type);

        return [
            'id' => $media->uuid,
            'name' => $media->name,
            'size' => $media->size,
            'extension' => $type?->extension(),
            'previewable' => $type?->isPreviewable() ?? false,
            'uploader' => $media->uploader?->only(['id', 'name']),
            'created_at' => $media->created_at?->toIso8601String(),
        ];
    }
}
