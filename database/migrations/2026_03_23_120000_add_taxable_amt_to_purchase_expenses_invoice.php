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
        if (! Schema::hasColumn('purchase', 'taxable_amt')) {
            Schema::table('purchase', function (Blueprint $table) {
                $table->decimal('taxable_amt', 12, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('expenses', 'taxable_amt')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->decimal('taxable_amt', 12, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('invoice', 'taxable_amt')) {
            Schema::table('invoice', function (Blueprint $table) {
                $table->decimal('taxable_amt', 12, 2)->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase', function (Blueprint $table) {
            $table->dropColumn('taxable_amt');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('taxable_amt');
        });

        Schema::table('invoice', function (Blueprint $table) {
            $table->dropColumn('taxable_amt');
        });
    }
};
