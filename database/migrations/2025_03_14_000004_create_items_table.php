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
        if (Schema::hasTable('items')) {
            return;
        }
        Schema::create('items', function (Blueprint $table) {
            $table->id('item_id');
            $table->string('item_name');
            $table->string('hsncode', 50)->nullable();
            $table->text('description')->nullable();
            $table->decimal('rate', 12, 2)->default(0);
            $table->tinyInteger('with_without')->default(0)->comment('0 or 1');
            $table->decimal('gst', 8, 2)->nullable();
            $table->decimal('gst_amt', 12, 2)->nullable();
        });
    }

};
