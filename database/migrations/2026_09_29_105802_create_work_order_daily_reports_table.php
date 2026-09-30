<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily progress reports posted during Pelaksanaan (FLOW.md §7): one per
 * work order per date (a display-timezone calendar date, never converted),
 * with a short note and links; its files are attachments of the report.
 * Reports are edited, never deleted.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('work_order_daily_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->restrictOnDelete();
            $table->date('report_date');
            $table->text('note');
            $table->jsonb('links')->default('[]');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['work_order_id', 'report_date']);
            // "Belum lapor" looks up today's reports across work orders.
            $table->index('report_date');
        });

        DB::statement("ALTER TABLE work_order_daily_reports ADD CONSTRAINT work_order_daily_reports_note_check CHECK (btrim(note) <> '')");
        DB::statement("ALTER TABLE work_order_daily_reports ADD CONSTRAINT work_order_daily_reports_links_check CHECK (jsonb_typeof(links) = 'array')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_daily_reports');
    }
};
