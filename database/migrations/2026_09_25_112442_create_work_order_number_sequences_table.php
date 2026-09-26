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
        // One counter per numbering scope, e.g. "WO/IT/2026/09/{SEQ:4}".
        Schema::create('work_order_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('scope')->unique();
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_number_sequences');
    }
};
