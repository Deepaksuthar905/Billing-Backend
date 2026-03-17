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
        if (Schema::hasTable('party')) {
            return;
        }
        Schema::create('party', function (Blueprint $table) {
            $table->id('pid');
            $table->string('partyname');
            $table->string('mobno', 20)->nullable();
            $table->string('cid', 50)->nullable();
            $table->string('billing_name')->nullable();
            $table->string('gst_no', 50)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->tinyInteger('gst_reg')->default(0)->comment('0=No, 1=Yes');
            $table->tinyInteger('same_state')->default(0)->comment('0=No, 1=Yes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('party');
    }
};
