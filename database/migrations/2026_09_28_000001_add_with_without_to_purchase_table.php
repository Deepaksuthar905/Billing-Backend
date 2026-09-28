<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase')) {
            return;
        }

        Schema::table('purchase', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase', 'with_without')) {
                $table->tinyInteger('with_without')->nullable()->after('taxable_amt')->comment('1=price with tax, 0=without tax');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchase')) {
            return;
        }

        Schema::table('purchase', function (Blueprint $table) {
            if (Schema::hasColumn('purchase', 'with_without')) {
                $table->dropColumn('with_without');
            }
        });
    }
};
