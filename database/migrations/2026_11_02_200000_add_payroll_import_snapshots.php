<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_runs') && ! Schema::hasColumn('payroll_runs', 'source')) {
            Schema::table('payroll_runs', function (Blueprint $table): void {
                $table->string('source', 20)->default('calculated');
                $table->string('original_name')->nullable();
                $table->string('file_path')->nullable();
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->timestamp('uploaded_at')->nullable();
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->string('payslip_status', 20)->nullable();
                $table->json('import_rows')->nullable();
            });
        }

        if (Schema::hasTable('payroll_items') && ! Schema::hasColumn('payroll_items', 'import_supplied')) {
            Schema::table('payroll_items', function (Blueprint $table): void {
                $table->decimal('other_earnings', 12, 2)->nullable();
                $table->decimal('professional_tax', 12, 2)->nullable();
                $table->decimal('other_deductions', 12, 2)->nullable();
                $table->string('payslip_path')->nullable();
                $table->text('payslip_error')->nullable();
                $table->json('import_supplied')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_items') && Schema::hasColumn('payroll_items', 'import_supplied')) {
            Schema::table('payroll_items', function (Blueprint $table): void {
                $table->dropColumn([
                    'other_earnings',
                    'professional_tax',
                    'other_deductions',
                    'payslip_path',
                    'payslip_error',
                    'import_supplied',
                ]);
            });
        }

        if (Schema::hasTable('payroll_runs') && Schema::hasColumn('payroll_runs', 'source')) {
            Schema::table('payroll_runs', function (Blueprint $table): void {
                $table->dropColumn([
                    'source',
                    'original_name',
                    'file_path',
                    'uploaded_by',
                    'uploaded_at',
                    'confirmed_by',
                    'payslip_status',
                    'import_rows',
                ]);
            });
        }
    }
};
