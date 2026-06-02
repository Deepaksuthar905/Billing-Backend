<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoice')) {
            return;
        }

        Schema::table('invoice', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice', 'isdel')) {
                $table->tinyInteger('isdel')->default(0)->after('balance');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoice')) {
            return;
        }

        Schema::table('invoice', function (Blueprint $table) {
            if (Schema::hasColumn('invoice', 'isdel')) {
                $table->dropColumn('isdel');
            }
        });
    }
};
