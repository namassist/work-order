<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every department belongs to exactly one company. Not null without a
 * backfill: existing databases are rebuilt with migrate:fresh.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->foreignId('company_id')->after('id')->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
