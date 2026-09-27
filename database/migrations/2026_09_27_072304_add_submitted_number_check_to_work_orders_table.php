<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Visibility for the executor company (WorkOrder::isVisibleTo()) treats a
 * number as "submitted at least once", so a submitted status must never lack
 * one. Step 2 of docs/FLOW.md must extend the list to every status after the
 * first submission (Ditolak, Dikerjakan, Penagihan, Selesai).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_submitted_number_check CHECK (status NOT IN ('diajukan') OR number IS NOT NULL)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT work_orders_submitted_number_check');
    }
};
