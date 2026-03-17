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
        if (Schema::hasTable('invoice')) {
            return;
        }
        Schema::create('invoice', function (Blueprint $table) {
            $table->id('invid');
            $table->string('inv_no')->nullable();
            $table->date('dt')->nullable();
            $table->string('state', 100)->nullable();
            $table->unsignedBigInteger('pid');
            $table->decimal('gst', 12, 2)->nullable();
            $table->decimal('payment', 12, 2)->nullable();
            $table->decimal('cgst', 12, 2)->nullable();
            $table->decimal('sgst', 12, 2)->nullable();
            $table->decimal('igst', 12, 2)->nullable();
            $table->tinyInteger('paytype')->default(0)->comment('0 or 1');
            $table->decimal('paynow', 12, 2)->nullable();
            $table->unsignedBigInteger('payby')->nullable();
            $table->string('refno', 100)->nullable();
            $table->decimal('paylater', 12, 2)->nullable();
            $table->decimal('balance', 12, 2)->nullable();

            $table->foreign('pid')->references('pid')->on('party');
        });
    }
};
