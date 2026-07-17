<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('salary_payments')) {
            return;
        }

        Schema::disableForeignKeyConstraints();

        foreach (['fk_salary_payby', 'fk_salary_emp'] as $fk) {
            try {
                Schema::table('salary_payments', function (Blueprint $table) use ($fk) {
                    $table->dropForeign($fk);
                });
            } catch (\Throwable) {
                // FK may not exist if manual CREATE failed midway.
            }
        }

        // Match pay_by.pbid and employees.empid (BIGINT UNSIGNED from Laravel id()).
        DB::statement('ALTER TABLE `salary_payments` MODIFY `salid` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        DB::statement('ALTER TABLE `salary_payments` MODIFY `empid` BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE `salary_payments` MODIFY `payby` BIGINT UNSIGNED NULL');

        Schema::table('salary_payments', function (Blueprint $table) {
            try {
                $table->foreign('empid', 'fk_salary_emp')
                    ->references('empid')->on('employees')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
            } catch (\Throwable) {
                // FK may already exist.
            }

            try {
                $table->foreign('payby', 'fk_salary_payby')
                    ->references('pbid')->on('pay_by')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            } catch (\Throwable) {
                // FK may already exist.
            }
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // No rollback — column types should stay BIGINT UNSIGNED.
    }
};
