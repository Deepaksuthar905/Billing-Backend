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
        if (Schema::hasTable('purchase_head')) {
            return;
        }
        Schema::create('purchase_head', function (Blueprint $table) {
            $table->id('prhid');
            $table->string('name');
            $table->tinyInteger('type')->default(0)->comment('0 or 1');
        });
    }
};
