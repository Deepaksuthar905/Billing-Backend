<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('expenses') && ! Schema::hasColumn('expenses', 'isdel')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->tinyInteger('isdel')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('expenses') && Schema::hasColumn('expenses', 'isdel')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropColumn('isdel');
            });
        }
    }
};
