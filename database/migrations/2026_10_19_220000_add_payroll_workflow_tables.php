<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('salary_structures') && ! Schema::hasColumn('salary_structures', 'currency')) {
            Schema::table('salary_structures', function (Blueprint $table) {
                $table->char('currency', 3)->nullable()->after('effective_from');
                $table->unique(['employee_id', 'effective_from']);
            });
        }

        if (Schema::hasTable('payroll_runs')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                if (! Schema::hasColumn('payroll_runs', 'calculated_by')) {
                    $table->foreignId('calculated_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
                    $table->timestamp('calculated_at')->nullable();
                    $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                    $table->timestamp('reviewed_at')->nullable();
                    $table->text('review_note')->nullable();
                    $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                    $table->timestamp('approved_at')->nullable();
                    $table->json('warnings')->nullable();
                }
            });

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE payroll_runs MODIFY status VARCHAR(191) NOT NULL DEFAULT 'draft'");
            }
        }

        if (Schema::hasTable('payroll_items')) {
            if (! Schema::hasColumn('payroll_items', 'currency')) {
                Schema::table('payroll_items', function (Blueprint $table) {
                    $table->char('currency', 3)->nullable();
                });
            }

            if (! $this->hasIndex('payroll_items', 'payroll_items_run_employee_unique')) {
                Schema::table('payroll_items', function (Blueprint $table) {
                    $table->unique(['payroll_run_id', 'employee_id'], 'payroll_items_run_employee_unique');
                });
            }

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE payroll_items MODIFY payroll_status VARCHAR(191) NOT NULL DEFAULT 'draft'");
            }
        }

        if (Schema::hasTable('salary_structures') && Schema::hasTable('users') && ! Schema::hasTable('salary_structure_events')) {
            Schema::create('salary_structure_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('salary_structure_id')->constrained('salary_structures')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users');
                $table->string('action', 40);
                $table->date('effective_from');
                $table->timestamp('created_at')->nullable();
                $table->index(['salary_structure_id', 'created_at']);
            });
        }

        if (Schema::hasTable('payroll_runs') && Schema::hasTable('users') && ! Schema::hasTable('payroll_events')) {
            Schema::create('payroll_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users');
                $table->string('action', 40);
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['payroll_run_id', 'created_at']);
            });
        }

        if (Schema::hasTable('payroll_items') && Schema::hasTable('users') && ! Schema::hasTable('payroll_payments')) {
            Schema::create('payroll_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('payroll_item_id')->unique()->constrained('payroll_items')->restrictOnDelete();
                $table->decimal('amount', 12, 2);
                $table->string('status', 20)->default('pending');
                $table->date('paid_on')->nullable();
                $table->string('method', 40)->nullable();
                $table->string('reference', 80)->nullable();
                $table->text('note')->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_payments');
        Schema::dropIfExists('payroll_events');
        Schema::dropIfExists('salary_structure_events');

        if (Schema::hasTable('payroll_items') && Schema::hasColumn('payroll_items', 'currency')) {
            Schema::table('payroll_items', function (Blueprint $table) {
                $table->dropUnique('payroll_items_run_employee_unique');
                $table->dropColumn('currency');
            });
        }

        if (Schema::hasTable('payroll_runs') && Schema::hasColumn('payroll_runs', 'calculated_by')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->dropConstrainedForeignId('calculated_by');
                $table->dropColumn('calculated_at');
                $table->dropConstrainedForeignId('reviewed_by');
                $table->dropColumn(['reviewed_at', 'review_note']);
                $table->dropConstrainedForeignId('approved_by');
                $table->dropColumn(['approved_at', 'warnings']);
            });
        }

        if (Schema::hasTable('salary_structures') && Schema::hasColumn('salary_structures', 'currency')) {
            Schema::table('salary_structures', function (Blueprint $table) {
                $table->dropUnique(['employee_id', 'effective_from']);
                $table->dropColumn('currency');
            });
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        $indexes = Schema::getIndexes($table);

        foreach ($indexes as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
