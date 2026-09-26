<?php

namespace App\Actions\WorkOrders;

use App\Models\WorkOrder;
use RuntimeException;

/**
 * A comment change the rules refuse although the user may comment in
 * principle: the work order no longer accepts comments, or the edit window
 * has passed. The message is shown to the user.
 */
class CommentNotAllowed extends RuntimeException
{
    public static function readOnly(WorkOrder $workOrder): self
    {
        return new self(__('Work order :status; komentarnya hanya dapat dibaca.', [
            'status' => mb_strtolower($workOrder->status->label()),
        ]));
    }

    public static function alreadyDeleted(): self
    {
        return new self(__('Komentar sudah dihapus.'));
    }

    public static function editWindowPassed(): self
    {
        return new self(__('Komentar hanya dapat diubah atau dihapus dalam :minutes menit setelah dikirim.', [
            'minutes' => config()->integer('work_order.comments.edit_window_minutes'),
        ]));
    }
}
