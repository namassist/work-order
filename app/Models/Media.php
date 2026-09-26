<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

/**
 * An attachment. Stored on the private attachments disk under a random
 * name; `name` holds the original filename for display only, and
 * `mime_type` is the type detected from the file content.
 *
 * @property int|null $uploaded_by
 * @property-read User|null $uploader
 */
class Media extends BaseMedia
{
    /**
     * The user who uploaded the file, even if they were deleted later.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }
}
