<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\WorkOrder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes comment files that were uploaded but never posted: pending
 * uploads older than work_order.comments.pending_uploads.prune_after_hours.
 * Scheduled hourly (routes/console.php), so production needs the scheduler.
 */
#[Signature('work-orders:prune-comment-uploads')]
#[Description('Hapus unggahan komentar yang tidak pernah dikirim')]
class PruneCommentUploads extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cutoff = now()->subHours(config()->integer('work_order.comments.pending_uploads.prune_after_hours'));
        $deleted = 0;

        $workOrderIds = $this->abandoned($cutoff)->distinct()->pluck('model_id');

        foreach ($workOrderIds as $workOrderId) {
            // The lock waits for a comment claiming these uploads right now.
            DB::transaction(function () use ($workOrderId, $cutoff, &$deleted): void {
                WorkOrder::withTrashed()->lockForUpdate()->find($workOrderId);

                foreach ($this->abandoned($cutoff)->where('model_id', $workOrderId)->get() as $media) {
                    $media->delete();
                    $deleted++;
                }
            });
        }

        $this->info(__(':count unggahan komentar yang tidak dipakai dihapus.', ['count' => $deleted]));

        return self::SUCCESS;
    }

    /**
     * Pending comment uploads created before the cutoff.
     *
     * @return Builder<Media>
     */
    private function abandoned(\DateTimeInterface $cutoff): Builder
    {
        return Media::query()
            ->where('model_type', new WorkOrder()->getMorphClass())
            ->whereIn('collection_name', [WorkOrder::COMMENT_IMAGE_UPLOADS, WorkOrder::COMMENT_FILE_UPLOADS])
            ->where('created_at', '<', $cutoff);
    }
}
