<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FLOW.md §3: self-registered accounts are pending until an admin approves
 * or rejects them. Existing and admin-created accounts are approved.
 * `registered_at` is set only for self-registered accounts; `reviewed_by`
 * and `reviewed_at` record the last approval or rejection.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_status', 20)->default('approved')->after('is_active');
            $table->text('rejection_reason')->nullable()->after('account_status');
            $table->timestamp('registered_at')->nullable()->after('rejection_reason');
            $table->foreignId('reviewed_by')->nullable()->after('registered_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');

            $table->index(['account_status', 'registered_at']);
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_account_status_check CHECK (account_status IN ('pending', 'approved', 'rejected'))");
        // A rejection always carries its reason.
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_rejection_reason_check CHECK (account_status <> 'rejected' OR rejection_reason IS NOT NULL)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_rejection_reason_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_account_status_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['account_status', 'registered_at']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['account_status', 'rejection_reason', 'registered_at', 'reviewed_at']);
        });
    }
};
