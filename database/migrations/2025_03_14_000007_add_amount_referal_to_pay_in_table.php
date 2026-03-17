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
        Schema::table('pay_in', function (Blueprint $table) {
            if (! Schema::hasColumn('pay_in', 'amount')) {
                $table->decimal('amount', 12, 2)->nullable()->after('payby');
            }
            if (! Schema::hasColumn('pay_in', 'referal')) {
                $table->string('referal', 100)->nullable()->after('amount');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pay_in', function (Blueprint $table) {
            $table->dropColumn(['amount', 'referal']);
        });
    }
};
