<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FLOW.md §4: a work order has a requester department (client company), a
 * requester (an account or, when entered on behalf, a contact name), the
 * user who entered it (created_by), and a target department (executor
 * company), required from the first submission on. Existing work orders
 * were entered by their requester, so requester_id is backfilled from
 * created_by.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->renameColumn('department_id', 'requester_department_id');
        });

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->renameIndex('work_orders_department_id_created_at_index', 'work_orders_requester_department_id_created_at_index');
            $table->foreignId('target_department_id')->nullable()->after('requester_department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('requester_id')->nullable()->after('target_department_id')->constrained('users')->restrictOnDelete();
            $table->string('requester_name', 150)->nullable()->after('requester_id');

            $table->index('target_department_id');
            $table->index('created_by');
        });

        DB::statement('ALTER TABLE work_orders RENAME CONSTRAINT work_orders_department_id_foreign TO work_orders_requester_department_id_foreign');
        DB::table('work_orders')->whereNull('requester_id')->update(['requester_id' => DB::raw('created_by')]);

        // Exactly one of the requester account and the contact name is set.
        DB::statement('ALTER TABLE work_orders ADD CONSTRAINT work_orders_requester_check CHECK ((requester_id IS NULL) <> (requester_name IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT work_orders_requester_check');
        DB::statement('ALTER TABLE work_orders RENAME CONSTRAINT work_orders_requester_department_id_foreign TO work_orders_department_id_foreign');

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropIndex(['created_by']);
            $table->dropIndex(['target_department_id']);
            $table->dropColumn('requester_name');
            $table->dropConstrainedForeignId('requester_id');
            $table->dropConstrainedForeignId('target_department_id');
            $table->renameIndex('work_orders_requester_department_id_created_at_index', 'work_orders_department_id_created_at_index');
        });

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->renameColumn('requester_department_id', 'department_id');
        });
    }
};
