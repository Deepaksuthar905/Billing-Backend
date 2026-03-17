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
        if (Schema::hasTable('pay_by')) {
            return;
        }
        Schema::create('pay_by', function (Blueprint $table) {
            $table->id('pbid');
            $table->tinyInteger('type')->default(0)->comment('0 or 1');
            $table->string('name');
            $table->string('detail')->nullable();
            $table->decimal('prebalance', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);
        });
    }
};
