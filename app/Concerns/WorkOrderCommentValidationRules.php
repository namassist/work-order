<?php

namespace App\Concerns;

use App\Support\Comments\CommentHtml;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The request rules of a comment, shared by posting and editing. The body's
 * content and whether the files are the author's are checked by the comment
 * actions (CommentContent), with the work order locked; the size is checked
 * here too, so an oversized body is refused before any lock is taken.
 */
trait WorkOrderCommentValidationRules
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function commentRules(): array
    {
        return [
            'body' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > CommentHtml::MAX_HTML_BYTES) {
                    $fail(__('Komentar terlalu besar. Kurangi format atau pecah menjadi beberapa komentar.'));
                }
            }],
            'attachments' => ['nullable', 'array', 'max:50'],
            'attachments.*' => ['string', 'uuid', 'distinct'],
        ];
    }
}
