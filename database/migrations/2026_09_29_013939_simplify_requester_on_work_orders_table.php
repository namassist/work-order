<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FLOW.md v2 §4: IC never logs in, so a work order's requester is always a
 * contact name (required, not blank) and the requester account goes. Work
 * orders that had an account keep its name as their contact name. Adds the
 * optional PIC Work Order name (informational, the Unggul staff member IC
 * contacted).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('UPDATE work_orders SET requester_name = users.name FROM users WHERE work_orders.requester_id = users.id AND work_orders.requester_name IS NULL');
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT work_orders_requester_check');

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requester_id');
            $table->string('requester_name', 150)->nullable(false)->change();
            $table->string('pic_name', 150)->nullable()->after('requester_name');
        });

        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_requester_name_check CHECK (btrim(requester_name) <> '')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT work_orders_requester_name_check');

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropColumn('pic_name');
            $table->string('requester_name', 150)->nullable()->change();
            $table->foreignId('requester_id')->nullable()->after('target_department_id')->constrained('users')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE work_orders ADD CONSTRAINT work_orders_requester_check CHECK ((requester_id IS NULL) <> (requester_name IS NULL))');
    }
};
