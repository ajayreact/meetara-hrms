<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasTable('employees') || ! Schema::hasTable('users')) {
            return;
        }

        $mysql = Schema::getConnection()->getDriverName() === 'mysql';

        if (! Schema::hasTable('onboardings')) {
            Schema::create('onboardings', function (Blueprint $table) use ($mysql): void {
                if ($mysql) {
                    $table->engine = 'InnoDB';
                }
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('employee_id')->unique()->constrained('employees');
                $table->foreignId('onboarding_template_id')->nullable();
                $table->string('status', 40)->default('pending');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('welcomed_at')->nullable();
                $table->timestamps();
            });
        } elseif (! Schema::hasColumn('onboardings', 'welcomed_at')) {
            Schema::table('onboardings', function (Blueprint $table): void {
                $table->timestamp('welcomed_at')->nullable();
            });
        }

        if (Schema::hasTable('onboardings') && ! $this->hasIndex('onboardings', 'onboardings_employee_id_unique')) {
            $duplicate = DB::table('onboardings')
                ->select('employee_id')
                ->groupBy('employee_id')
                ->havingRaw('count(*) > 1')
                ->exists();

            if (! $duplicate) {
                Schema::table('onboardings', function (Blueprint $table): void {
                    $table->unique('employee_id');
                });
            }
        }

        if (! Schema::hasTable('onboarding_tasks')) {
            Schema::create('onboarding_tasks', function (Blueprint $table) use ($mysql): void {
                if ($mysql) {
                    $table->engine = 'InnoDB';
                }
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('onboarding_id')->constrained('onboardings');
                $table->string('code', 40);
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('assignee_role')->default('HR');
                $table->unsignedBigInteger('assigned_user_id')->nullable();
                $table->unsignedBigInteger('completed_by')->nullable();
                $table->date('due_date')->nullable();
                $table->string('status', 40)->default('pending');
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['onboarding_id', 'code']);
            });
        } else {
            Schema::table('onboarding_tasks', function (Blueprint $table): void {
                if (! Schema::hasColumn('onboarding_tasks', 'code')) {
                    $table->string('code', 40)->nullable();
                }
                if (! Schema::hasColumn('onboarding_tasks', 'completed_by')) {
                    $table->unsignedBigInteger('completed_by')->nullable();
                }
            });
        }

        if (! Schema::hasTable('employee_documents')) {
            Schema::create('employee_documents', function (Blueprint $table) use ($mysql): void {
                if ($mysql) {
                    $table->engine = 'InnoDB';
                }
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('employee_id')->constrained('employees');
                $table->unsignedBigInteger('document_type_id')->nullable();
                $table->string('category', 40);
                $table->string('title');
                $table->string('file_path');
                $table->string('original_name');
                $table->string('review_status', 40)->default('pending');
                $table->unsignedBigInteger('uploaded_by')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->unsignedBigInteger('replaces_document_id')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'employee_id', 'category']);
            });
        } else {
            Schema::table('employee_documents', function (Blueprint $table): void {
                if (! Schema::hasColumn('employee_documents', 'category')) {
                    $table->string('category', 40)->default('other');
                }
                if (! Schema::hasColumn('employee_documents', 'review_status')) {
                    $table->string('review_status', 40)->default('pending');
                }
                if (! Schema::hasColumn('employee_documents', 'reviewed_by')) {
                    $table->unsignedBigInteger('reviewed_by')->nullable();
                }
                if (! Schema::hasColumn('employee_documents', 'reviewed_at')) {
                    $table->timestamp('reviewed_at')->nullable();
                }
                if (! Schema::hasColumn('employee_documents', 'review_note')) {
                    $table->text('review_note')->nullable();
                }
                if (! Schema::hasColumn('employee_documents', 'replaces_document_id')) {
                    $table->unsignedBigInteger('replaces_document_id')->nullable();
                }
            });
        }

        if (! Schema::hasTable('employee_record_events')) {
            Schema::create('employee_record_events', function (Blueprint $table) use ($mysql): void {
                if ($mysql) {
                    $table->engine = 'InnoDB';
                }
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('actor_id');
                $table->string('action', 40);
                $table->string('subject', 40)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'employee_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_record_events');

        if (Schema::hasTable('employee_documents')) {
            Schema::table('employee_documents', function (Blueprint $table): void {
                foreach (['replaces_document_id', 'review_note', 'reviewed_at', 'reviewed_by', 'review_status', 'category'] as $column) {
                    if (Schema::hasColumn('employee_documents', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('onboarding_tasks')) {
            Schema::table('onboarding_tasks', function (Blueprint $table): void {
                foreach (['completed_by', 'code'] as $column) {
                    if (Schema::hasColumn('onboarding_tasks', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('onboardings') && Schema::hasColumn('onboardings', 'welcomed_at')) {
            Schema::table('onboardings', function (Blueprint $table): void {
                $table->dropColumn('welcomed_at');
            });
        }

        if (Schema::hasTable('onboardings') && $this->hasIndex('onboardings', 'onboardings_employee_id_unique')) {
            Schema::table('onboardings', function (Blueprint $table): void {
                $table->dropUnique('onboardings_employee_id_unique');
            });
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? '') === $name) {
                return true;
            }
        }

        return false;
    }
};
