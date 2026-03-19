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
        Schema::table('purchase', function (Blueprint $table) {
            $table->dropForeign(['prhid']);
            $table->dropColumn('prhid');
        });
        Schema::dropIfExists('purchase_head');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('purchase_head', function (Blueprint $table) {
            $table->id('prhid');
            $table->string('name');
            $table->tinyInteger('type')->default(0)->comment('0 or 1');
        });
        Schema::table('purchase', function (Blueprint $table) {
            $table->unsignedBigInteger('prhid')->after('prid');
            $table->foreign('prhid')->references('prhid')->on('purchase_head');
        });
    }
};
