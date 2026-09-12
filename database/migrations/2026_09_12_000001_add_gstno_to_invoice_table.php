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
            if (! Schema::hasColumn('invoice', 'gstno')) {
                $table->string('gstno', 20)->nullable()->after('state')->comment('Buyer GSTIN from sync');
            }
            if (! Schema::hasColumn('invoice', 'gst_regular')) {
                $table->tinyInteger('gst_regular')->default(0)->after('gstno')->comment('1=GST claimed on sync');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoice')) {
            return;
        }

        Schema::table('invoice', function (Blueprint $table) {
            if (Schema::hasColumn('invoice', 'gst_regular')) {
                $table->dropColumn('gst_regular');
            }
            if (Schema::hasColumn('invoice', 'gstno')) {
                $table->dropColumn('gstno');
            }
        });
    }
};
