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
        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('igst', 12, 2)->nullable()->after('payment');
            $table->decimal('cgst', 12, 2)->nullable()->after('igst');
            $table->decimal('sgst', 12, 2)->nullable()->after('cgst');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['igst', 'cgst', 'sgst']);
        });
    }
    
};
