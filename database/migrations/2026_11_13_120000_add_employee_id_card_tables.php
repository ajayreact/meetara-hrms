<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('id_card_company_profiles')) {
            Schema::create('id_card_company_profiles', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('country', 10);
                $table->string('legal_name');
                $table->string('display_name');
                $table->string('logo_path')->nullable();
                $table->string('logo_mime', 40)->nullable();
                $table->text('office_address');
                $table->string('contact_number')->nullable();
                $table->string('email')->nullable();
                $table->string('website')->nullable();
                $table->text('footer_address');
                $table->string('status', 20)->default('active');
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'country'], 'id_card_company_profiles_tenant_country_unique');
                $table->index(['tenant_id', 'status'], 'id_card_company_profiles_tenant_status_index');
            });
            $this->foreign('id_card_company_profiles', 'tenant_id', 'tenants');
            $this->foreign('id_card_company_profiles', 'updated_by', 'users');
        }

        if (! Schema::hasTable('employee_id_cards')) {
            Schema::create('employee_id_cards', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('employee_id');
                $table->string('country', 10);
                $table->unsignedBigInteger('company_profile_id')->nullable();
                $table->string('blood_group', 20)->nullable();
                $table->string('emergency_contact_number', 40)->nullable();
                $table->string('photo_path')->nullable();
                $table->string('photo_mime', 40)->nullable();
                $table->string('status', 20)->default('draft');
                $table->string('card_number')->nullable();
                $table->string('qr_token', 80);
                $table->timestamp('issued_at')->nullable();
                $table->timestamp('deactivated_at')->nullable();
                $table->unsignedBigInteger('reissued_from_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique('qr_token', 'employee_id_cards_qr_token_unique');
                $table->index(['tenant_id', 'employee_id'], 'employee_id_cards_tenant_employee_index');
                $table->index(['tenant_id', 'status'], 'employee_id_cards_tenant_status_index');
                $table->index(['tenant_id', 'country'], 'employee_id_cards_tenant_country_index');
                $table->index(['tenant_id', 'company_profile_id'], 'employee_id_cards_tenant_profile_index');
            });
            $this->foreign('employee_id_cards', 'tenant_id', 'tenants');
            $this->foreign('employee_id_cards', 'employee_id', 'employees');
            $this->foreign('employee_id_cards', 'company_profile_id', 'id_card_company_profiles');
            $this->foreign('employee_id_cards', 'reissued_from_id', 'employee_id_cards');
            $this->foreign('employee_id_cards', 'created_by', 'users');
            $this->foreign('employee_id_cards', 'updated_by', 'users');
        }

        if (! Schema::hasTable('employee_id_card_issues')) {
            Schema::create('employee_id_card_issues', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('id_card_id');
                $table->string('pdf_path')->nullable();
                $table->json('payload_json');
                $table->unsignedBigInteger('issued_by')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'id_card_id'], 'employee_id_card_issues_tenant_card_index');
            });
            $this->foreign('employee_id_card_issues', 'tenant_id', 'tenants');
            $this->foreign('employee_id_card_issues', 'id_card_id', 'employee_id_cards');
            $this->foreign('employee_id_card_issues', 'issued_by', 'users');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_id_card_issues');
        Schema::dropIfExists('employee_id_cards');
        Schema::dropIfExists('id_card_company_profiles');
    }

    private function foreign(string $table, string $column, string $references): void
    {
        if (! Schema::hasTable($references) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $references): void {
            $blueprint->foreign($column)->references('id')->on($references);
        });
    }
};
