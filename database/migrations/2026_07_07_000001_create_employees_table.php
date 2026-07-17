<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees')) {
            return;
        }

        Schema::create('employees', function (Blueprint $table) {
            $table->id('empid');
            $table->string('emp_code', 20)->nullable()->comment('Optional code: EMP001');
            $table->string('empname', 150);
            $table->string('mobno', 15)->nullable();
            $table->string('designation', 100)->nullable();
            $table->date('joining_date')->nullable();
            $table->decimal('monthly_salary', 12, 2)->default(0)->comment('Default gross for auto-fill');
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account', 30)->nullable();
            $table->string('ifsc', 11)->nullable();
            $table->string('pan_no', 10)->nullable();
            $table->string('addr', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->tinyInteger('isdel')->default(0);

            $table->unique('emp_code', 'uk_employees_emp_code');
            $table->index('empname', 'idx_employees_name');
            $table->index('is_active', 'idx_employees_active');
            $table->index('isdel', 'idx_employees_isdel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
