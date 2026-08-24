<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('monthly_reports')) {
            return;
        }

        Schema::create('monthly_reports', function (Blueprint $table) {
            $table->id('mrid');
            $table->char('report_month', 7)->comment('YYYY-MM e.g. 2026-07');
            $table->decimal('total_invoice_amt', 14, 2)->default(0);
            $table->unsignedInteger('total_invoice_count')->default(0);
            $table->decimal('total_expense_amt', 14, 2)->default(0);
            $table->unsignedInteger('total_expense_count')->default(0);
            $table->json('expense_by_head')->nullable()->comment('[{exhid, name, total, count}]');
            $table->dateTime('generated_at')->nullable();
            $table->tinyInteger('isdel')->default(0);

            $table->unique('report_month', 'uk_monthly_reports_month');
            $table->index('isdel', 'idx_monthly_reports_isdel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_reports');
    }
};
