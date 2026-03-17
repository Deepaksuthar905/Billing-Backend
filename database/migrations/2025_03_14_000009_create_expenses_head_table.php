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
        if (Schema::hasTable('expenses_head')) {
            return;
        }
        Schema::create('expenses_head', function (Blueprint $table) {
            $table->id('exhid');
            $table->string('name');
            $table->tinyInteger('type')->default(0)->comment('0 or 1');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses_head');
    }
};
