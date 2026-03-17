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
        if (Schema::hasTable('expenses')) {
            return;
        }
        Schema::create('expenses', function (Blueprint $table) {
            $table->id('exid');
            $table->unsignedBigInteger('exhid');
            $table->text('description')->nullable();
            $table->unsignedInteger('receipt_no')->nullable()->comment('App generates next number');
            $table->decimal('payment', 12, 2)->nullable();
            $table->date('dt')->nullable();
            $table->unsignedBigInteger('party')->nullable()->comment('FK to party.pid');
            $table->tinyInteger('payby')->default(0)->comment('0 or 1');
            $table->string('refno', 100)->nullable();

            $table->foreign('exhid')->references('exhid')->on('expenses_head');
            $table->foreign('party')->references('pid')->on('party');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
