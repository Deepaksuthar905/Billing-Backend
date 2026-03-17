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
        if (Schema::hasTable('purchase')) {
            return;
        }
        Schema::create('purchase', function (Blueprint $table) {
            $table->id('prid');
            $table->unsignedBigInteger('prhid');
            $table->string('p_inv_no', 100)->nullable();
            $table->date('dt')->nullable();
            $table->string('state', 100)->nullable();
            $table->decimal('payment', 12, 2)->nullable();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->decimal('gst', 12, 2)->nullable();
            $table->decimal('cgst', 12, 2)->nullable();
            $table->decimal('sgst', 12, 2)->nullable();
            $table->decimal('igst', 12, 2)->nullable();
            $table->unsignedBigInteger('payby')->nullable();
            $table->string('refno', 100)->nullable();

            $table->foreign('prhid')->references('prhid')->on('purchase_head');
            $table->foreign('party_id')->references('pid')->on('party');
            $table->foreign('payby')->references('pbid')->on('pay_by');
        });
    }
};
