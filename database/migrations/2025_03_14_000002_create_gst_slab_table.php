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
        if (Schema::hasTable('gst_slab')) {
            return;
        }
        Schema::create('gst_slab', function (Blueprint $table) {
            $table->id('gst_id');
            //in igst and cgst and sgst we store percentage value
            $table->decimal('igst', 8, 2)->default(0)->comment('0=No, 1=Yes');
            $table->decimal('cgst', 8, 2)->default(0)->comment('0=No, 1=Yes');
            $table->decimal('sgst', 8, 2)->default(0)->comment('0=No, 1=Yes');
            $table->string('label')->nullable();
        });
    }
};
