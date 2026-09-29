<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Segregation of duties on payment is a setting now, off by default
 * (FLOW.md §10, work_order.payment.segregation_of_duties), so the database
 * no longer refuses an invoice paid by whoever issued or corrected it;
 * ConfirmWorkOrderPayment enforces it when the setting is on.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE work_order_invoices DROP CONSTRAINT work_order_invoices_segregation_check');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE work_order_invoices ADD CONSTRAINT work_order_invoices_segregation_check CHECK (paid_by IS NULL OR (paid_by <> issued_by AND paid_by IS DISTINCT FROM corrected_by))');
    }
};
