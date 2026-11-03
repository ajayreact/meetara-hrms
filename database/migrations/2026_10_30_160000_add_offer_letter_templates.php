<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenants') && Schema::hasTable('users') && ! Schema::hasTable('company_legal_profiles')) {
            Schema::create('company_legal_profiles', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->unique()->constrained('tenants');
                $table->string('legal_name');
                $table->text('legal_address');
                $table->string('registration_details')->nullable();
                $table->string('signatory_name');
                $table->string('signatory_designation');
                $table->string('hr_email');
                $table->string('logo_path')->nullable();
                $table->string('signature_path')->nullable();
                $table->string('status', 20)->default('draft');
                $table->foreignId('updated_by')->nullable()->constrained('users');
                $table->foreignId('verified_by')->nullable()->constrained('users');
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('tenants') && Schema::hasTable('users') && ! Schema::hasTable('company_legal_profile_events')) {
            Schema::create('company_legal_profile_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('actor_id')->constrained('users');
                $table->string('action', 40);
                $table->json('previous_values')->nullable();
                $table->json('updated_values')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'created_at']);
            });
        }

        if (Schema::hasTable('tenants') && Schema::hasTable('users') && ! Schema::hasTable('offer_letterheads')) {
            Schema::create('offer_letterheads', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->string('country', 10);
                $table->unsignedInteger('version');
                $table->string('source_path');
                $table->string('background_path')->nullable();
                $table->string('mime', 80);
                $table->string('original_name');
                $table->string('status', 20)->default('active');
                $table->string('page_size', 10)->default('A4');
                $table->unsignedSmallInteger('margin_top')->default(120);
                $table->unsignedSmallInteger('margin_right')->default(48);
                $table->unsignedSmallInteger('margin_bottom')->default(56);
                $table->unsignedSmallInteger('margin_left')->default(48);
                $table->foreignId('uploaded_by')->nullable()->constrained('users');
                $table->timestamps();
                $table->unique(['tenant_id', 'country', 'version']);
                $table->index(['tenant_id', 'country', 'status']);
            });
        }

        if (Schema::hasTable('tenants') && Schema::hasTable('users') && ! Schema::hasTable('offer_letter_templates')) {
            Schema::create('offer_letter_templates', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->string('country', 10);
                $table->string('name');
                $table->string('status', 20)->default('draft');
                $table->unsignedInteger('current_version')->default(1);
                $table->foreignId('created_by')->nullable()->constrained('users');
                $table->timestamps();
                $table->index(['tenant_id', 'country', 'status']);
            });
        }

        if (Schema::hasTable('offer_letter_templates') && Schema::hasTable('users') && ! Schema::hasTable('offer_letter_template_versions')) {
            Schema::create('offer_letter_template_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('template_id')->constrained('offer_letter_templates');
                $table->unsignedInteger('version');
                $table->string('country', 10);
                $table->text('body');
                $table->foreignId('letterhead_id')->nullable()->constrained('offer_letterheads');
                $table->string('status', 20)->default('draft');
                $table->foreignId('created_by')->nullable()->constrained('users');
                $table->foreignId('submitted_by')->nullable()->constrained('users');
                $table->timestamp('submitted_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users');
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->unique(['template_id', 'version']);
                $table->index(['tenant_id', 'country', 'status']);
            });
        }

        if (Schema::hasTable('offer_letters') && Schema::hasTable('users') && ! Schema::hasTable('offer_letter_downloads')) {
            Schema::create('offer_letter_downloads', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('offer_letter_id')->constrained('offer_letters');
                $table->foreignId('user_id')->constrained('users');
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'offer_letter_id']);
            });
        }

        if (! Schema::hasTable('offer_letters') || Schema::hasColumn('offer_letters', 'country')) {
            return;
        }

        Schema::table('offer_letters', function (Blueprint $table): void {
            $table->string('country', 10)->default('india');
            $table->string('reference_code', 40)->nullable();
            $table->unsignedBigInteger('template_version_id')->nullable();
            $table->string('employee_email')->nullable();
            $table->string('compensation_type', 20)->nullable();
            $table->decimal('hourly_rate', 12, 2)->nullable();
            $table->decimal('basic_salary', 12, 2)->nullable();
            $table->decimal('hra', 12, 2)->nullable();
            $table->decimal('special_allowance', 12, 2)->nullable();
            $table->unsignedTinyInteger('probation_months')->nullable();
            $table->string('department_name')->nullable();
            $table->string('work_location')->nullable();
            $table->string('employment_type')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->index(['tenant_id', 'country', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_letter_downloads');
        Schema::dropIfExists('offer_letter_template_versions');
        Schema::dropIfExists('offer_letter_templates');
        Schema::dropIfExists('offer_letterheads');
        Schema::dropIfExists('company_legal_profile_events');
        Schema::dropIfExists('company_legal_profiles');

        if (Schema::hasTable('offer_letters') && Schema::hasColumn('offer_letters', 'country')) {
            Schema::table('offer_letters', function (Blueprint $table): void {
                $table->dropIndex(['tenant_id', 'country', 'status']);
                $table->dropColumn([
                    'country', 'reference_code', 'template_version_id', 'employee_email', 'compensation_type',
                    'hourly_rate', 'basic_salary', 'hra', 'special_allowance', 'probation_months',
                    'department_name', 'work_location', 'employment_type', 'submitted_by', 'submitted_at',
                    'approved_by', 'approved_at',
                ]);
            });
        }
    }
};
