<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The invoice of a work order (FLOW.md §8), written when it moves to
 * Penagihan and paid when it moves to Selesai. One per work order for now
 * (unique work_order_id); instalments (termin, FLOW.md §11) would drop that
 * index and add a sequence, which is why the payment lives on this row and
 * not on the work order.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('work_order_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('number', 100);
            $table->date('invoice_date');
            $table->decimal('amount', 15, 2)->nullable();
            $table->date('due_date')->nullable();
            $table->date('paid_on')->nullable();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        // Invoice numbers are unique whatever their casing.
        DB::statement('CREATE UNIQUE INDEX work_order_invoices_number_lower_unique ON work_order_invoices (lower(number))');
        DB::statement('ALTER TABLE work_order_invoices ADD CONSTRAINT work_order_invoices_amount_check CHECK (amount IS NULL OR amount > 0)');
        DB::statement('ALTER TABLE work_order_invoices ADD CONSTRAINT work_order_invoices_due_date_check CHECK (due_date IS NULL OR due_date >= invoice_date)');
        DB::statement('ALTER TABLE work_order_invoices ADD CONSTRAINT work_order_invoices_paid_on_check CHECK (paid_on IS NULL OR paid_on >= invoice_date)');
        DB::statement('ALTER TABLE work_order_invoices ADD CONSTRAINT work_order_invoices_payment_check CHECK ((paid_on IS NULL) = (paid_by IS NULL))');
        // Segregation of duties (FLOW.md §8): whoever issued or last corrected
        // the invoice does not confirm its payment.
        DB::statement('ALTER TABLE work_order_invoices ADD CONSTRAINT work_order_invoices_segregation_check CHECK (paid_by IS NULL OR (paid_by <> issued_by AND paid_by IS DISTINCT FROM corrected_by))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_invoices');
    }
};
