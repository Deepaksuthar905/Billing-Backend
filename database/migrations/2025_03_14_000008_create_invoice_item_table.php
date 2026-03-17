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
        if (Schema::hasTable('invoice_item')) {
            return;
        }
        Schema::create('invoice_item', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inv_id');
            $table->unsignedBigInteger('item_id');
            $table->string('hsnocde', 50)->nullable();
            $table->text('description')->nullable();
            $table->decimal('rate', 12, 2)->default(0);
            $table->decimal('qty', 12, 2)->default(0);
            $table->decimal('payment', 12, 2)->nullable();
            $table->tinyInteger('with_without')->default(0)->comment('0 or 1');
            $table->decimal('gst', 8, 2)->nullable();
            $table->decimal('gst_amt', 12, 2)->nullable();

            $table->foreign('inv_id')->references('invid')->on('invoice');
            $table->foreign('item_id')->references('item_id')->on('items');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_item');
    }
};
