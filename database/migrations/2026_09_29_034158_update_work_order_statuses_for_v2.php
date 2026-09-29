<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The v2 status flow (FLOW.md §5, step 3): every status after the first
 * submission keeps its number, because WorkOrder::isVisibleTo() reads a
 * number as "was submitted". Every state whose requiresNumber() is true must
 * be listed here (WorkOrderStatusFlowTest checks it).
 *
 * The v1 statuses of the work order flow and billing, and the manual BAST
 * files, have no v2 counterpart to convert to (FLOW.md §15: there is no
 * production data), so a database that still holds them is refused rather
 * than silently rewritten: start from a fresh database (docs/DEPLOY.md).
 */
return new class extends Migration
{
    /**
     * The v1 statuses this migration refuses to find. The only place in the
     * application that still names them.
     */
    private const array RETIRED_STATUSES = ['dikerjakan', 'penagihan', 'selesai'];

    private const array NUMBERED_V2 = ['diajukan', 'ditolak', 'pelaksanaan', 'review_dokumen', 'approval_bast', 'bast_disetujui', 'closed'];

    private const array NUMBERED_V1 = ['diajukan', 'ditolak', 'dikerjakan', 'penagihan', 'selesai'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->ensureNoV1Data();

        $this->replaceNumberCheck(self::NUMBERED_V2);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->replaceNumberCheck(self::NUMBERED_V1);
    }

    /**
     * @throws RuntimeException when work orders, their history, or files still use the v1 flow
     */
    private function ensureNoV1Data(): void
    {
        $retired = DB::table('work_orders')->whereIn('status', self::RETIRED_STATUSES)->exists()
            || DB::table('work_order_status_histories')
                ->whereIn('from_status', self::RETIRED_STATUSES)
                ->orWhereIn('to_status', self::RETIRED_STATUSES)
                ->exists()
            || DB::table('media')->where('collection_name', 'bast')->exists();

        if ($retired) {
            throw new RuntimeException('Work orders still use the v1 statuses ('.implode(', ', self::RETIRED_STATUSES).') or manual BAST files. '
                .'They cannot be converted to the v2 flow: start from a fresh database (see docs/DEPLOY.md).');
        }
    }

    /**
     * @param  list<string>  $statuses
     */
    private function replaceNumberCheck(array $statuses): void
    {
        $list = implode(', ', array_map(fn (string $status): string => "'{$status}'", $statuses));

        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT work_orders_submitted_number_check');
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_submitted_number_check CHECK (status NOT IN ({$list}) OR number IS NOT NULL)");
    }
};
