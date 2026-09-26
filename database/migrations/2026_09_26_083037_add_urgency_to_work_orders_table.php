<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Existing work orders become normal
     * (App\Enums\WorkOrderUrgency).
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->string('urgency', 20)->nullable()->after('status');
        });

        DB::table('work_orders')->whereNull('urgency')->update(['urgency' => 'normal']);

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->string('urgency', 20)->default('normal')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropColumn('urgency');
        });
    }
};
