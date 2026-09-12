<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('items')) {
            return;
        }

        if (! Schema::hasColumn('items', 'sales')) {
            Schema::table('items', function (Blueprint $table) {
                $table->tinyInteger('sales')->default(0)->after('gst_amt')->comment('1=selling item, 0=not');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('items') && Schema::hasColumn('items', 'sales')) {
            Schema::table('items', function (Blueprint $table) {
                $table->dropColumn('sales');
            });
        }
    }
};
