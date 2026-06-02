<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pay_in')) {
            return;
        }

        Schema::table('pay_in', function (Blueprint $table) {
            if (! Schema::hasColumn('pay_in', 'ext_pnid')) {
                $table->unsignedBigInteger('ext_pnid')->nullable()->unique()->after('pinid');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pay_in')) {
            return;
        }

        Schema::table('pay_in', function (Blueprint $table) {
            if (Schema::hasColumn('pay_in', 'ext_pnid')) {
                $table->dropUnique(['ext_pnid']);
                $table->dropColumn('ext_pnid');
            }
        });
    }
};
