<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table): void {
            $table->id();
            // Assigned when the WO is submitted, so drafts have none.
            $table->string('number', 100)->nullable()->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_order_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 50)->index();
            $table->date('target_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['department_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
