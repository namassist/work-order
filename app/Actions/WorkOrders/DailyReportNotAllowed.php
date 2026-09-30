<?php

namespace App\Actions\WorkOrders;

use RuntimeException;

/**
 * A daily report change the rules refuse although the user holds the
 * permission for it, usually because the page went stale. The message is
 * shown to the user.
 */
class DailyReportNotAllowed extends RuntimeException
{
    /**
     * Reports are posted and edited only while the work is carried out.
     */
    public static function notInExecution(): self
    {
        return new self(__('Laporan harian hanya dapat ditambahkan atau diubah selama work order berstatus Pelaksanaan.'));
    }

    /**
     * The edit window (work_order.daily_reports.edit_extra_days) has passed.
     */
    public static function editWindowClosed(): self
    {
        return new self(__('Laporan harian ini sudah tidak dapat diubah.'));
    }
}
