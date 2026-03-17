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
        if (Schema::hasTable('pay_in')) {
            return;
        }
        Schema::create('pay_in', function (Blueprint $table) {
            $table->id('pinid');
            $table->unsignedBigInteger('party_id');
            $table->unsignedBigInteger('inv_id');
            $table->date('dt')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('payby')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('referal', 100)->nullable();

            $table->foreign('party_id')->references('pid')->on('party');
            $table->foreign('inv_id')->references('invid')->on('invoice');
            $table->foreign('payby')->references('pbid')->on('pay_by');
        });
    }
};
