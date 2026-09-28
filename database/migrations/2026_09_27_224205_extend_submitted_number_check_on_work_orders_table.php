<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every status after the first submission keeps the number given then,
 * because WorkOrder::isVisibleTo() reads a number as "was submitted". The
 * list names Penagihan and Selesai (step 3 of docs/FLOW.md) ahead of their
 * states, so they cannot be added without it. Every state whose
 * requiresTargetDepartment() is true must be listed here.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT work_orders_submitted_number_check');
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_submitted_number_check CHECK (status NOT IN ('diajukan', 'ditolak', 'dikerjakan', 'penagihan', 'selesai') OR number IS NOT NULL)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT work_orders_submitted_number_check');
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_submitted_number_check CHECK (status NOT IN ('diajukan') OR number IS NOT NULL)");
    }
};
