<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('salary_payments')) {
            return;
        }

        Schema::create('salary_payments', function (Blueprint $table) {
            $table->id('salid');
            $table->string('receipt_no', 30)->comment('SAL-001 - PDF receipt number');
            $table->unsignedBigInteger('empid');
            $table->char('salary_month', 7)->comment('YYYY-MM e.g. 2026-03');
            $table->date('dt')->comment('Actual payment date');
            $table->decimal('gross_amt', 12, 2)->default(0)->comment('Base monthly salary');
            $table->decimal('incentive_amt', 12, 2)->default(0)->comment('Monthly incentive');
            $table->decimal('arrears_amt', 12, 2)->default(0)->comment('Increment arrears / pending salary');
            $table->decimal('deduction_amt', 12, 2)->default(0)->comment('TDS, advance cut, PF, etc.');
            $table->decimal('net_amt', 12, 2)->comment('gross + incentive + arrears - deduction');
            $table->unsignedBigInteger('payby')->nullable()->comment('FK -> pay_by.pbid (Cash/Bank)');
            $table->string('refno', 100)->nullable()->comment('UTR / Cheque no.');
            $table->string('remarks', 255)->nullable();
            $table->enum('payment_type', ['salary', 'advance', 'bonus', 'final'])->default('salary');
            $table->tinyInteger('isdel')->default(0);

            $table->unique('receipt_no', 'uk_salary_receipt_no');
            $table->index('empid', 'idx_salary_empid');
            $table->index('salary_month', 'idx_salary_month');
            $table->index('dt', 'idx_salary_dt');
            $table->index('payby', 'idx_salary_payby');
            $table->index('isdel', 'idx_salary_isdel');
            $table->index('payment_type', 'idx_salary_payment_type');

            $table->foreign('empid', 'fk_salary_emp')
                ->references('empid')->on('employees')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('payby', 'fk_salary_payby')
                ->references('pbid')->on('pay_by')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        // Same employee + same month mein duplicate full salary rokne ke liye (advance/bonus multiple allowed).
        try {
            DB::statement(
                "CREATE UNIQUE INDEX uk_emp_month_salary ON salary_payments (empid, salary_month, (CASE WHEN payment_type = 'salary' THEN 1 ELSE NULL END))"
            );
        } catch (\Throwable) {
            // Older MySQL: duplicate monthly salary is enforced in API validation.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_payments');
    }
};
