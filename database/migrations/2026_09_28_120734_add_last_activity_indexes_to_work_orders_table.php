<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Last activity (updated_at) orders the dashboard's "WO Terbaru" and the
 * list's "Terakhir diperbarui" sort within a user's visibility: the
 * requester department for client company users, the target department for
 * executor users. The target index gains updated_at instead of standing
 * alone; its leading column still serves the target department lookups
 * and the list's target filter.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->index(['requester_department_id', 'updated_at']);
            $table->index(['target_department_id', 'updated_at']);
            $table->dropIndex(['target_department_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->index('target_department_id');
            $table->dropIndex(['target_department_id', 'updated_at']);
            $table->dropIndex(['requester_department_id', 'updated_at']);
        });
    }
};
