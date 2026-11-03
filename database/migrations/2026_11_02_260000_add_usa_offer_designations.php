<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('usa_offer_designations')) {
            Schema::create('usa_offer_designations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('designation_key', 80);
                $table->string('name', 120);
                $table->string('group_key', 30);
                $table->json('responsibilities');
                $table->json('factory_responsibilities');
                $table->boolean('is_active')->default(true);
                $table->boolean('is_builtin')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['tenant_id', 'designation_key']);
                $table->unique(['tenant_id', 'name']);
            });
        }

        if (! Schema::hasTable('usa_offer_profiles')) {
            Schema::create('usa_offer_profiles', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->string('company_name');
                $table->string('address_line')->nullable();
                $table->string('city_line')->nullable();
                $table->string('country_line')->nullable();
                $table->string('website')->nullable();
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('e_verify', 40)->nullable();
                $table->string('federal_id', 40)->nullable();
                $table->string('manager_name')->nullable();
                $table->string('manager_title')->nullable();
                $table->string('manager_email')->nullable();
                $table->string('manager_phone')->nullable();
                $table->string('hr_name')->nullable();
                $table->string('hr_title')->nullable();
                $table->string('hr_phone')->nullable();
                $table->string('template_version', 40);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('usa_offers')) {
            Schema::create('usa_offers', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('designation_id')->nullable();
                $table->string('designation_name');
                $table->json('responsibilities');
                $table->string('candidate_name');
                $table->string('candidate_email')->nullable();
                $table->string('candidate_phone')->nullable();
                $table->text('candidate_address')->nullable();
                $table->string('pay_type', 20);
                $table->decimal('pay_amount', 12, 2);
                $table->string('working_hours', 80);
                $table->string('work_arrangement', 120);
                $table->string('university', 160);
                $table->date('start_date');
                $table->date('issue_date');
                $table->json('profile_snapshot')->nullable();
                $table->string('template_version', 40);
                $table->string('status', 20)->default('draft');
                $table->string('file_path')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('usa_offers');
        Schema::dropIfExists('usa_offer_profiles');
        Schema::dropIfExists('usa_offer_designations');
    }
};
