<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenants') && Schema::hasTable('users') && ! Schema::hasTable('company_payroll_settings')) {
            Schema::create('company_payroll_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->unique()->constrained('tenants');
                $table->char('currency', 3)->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users');
                $table->timestamps();
            });
        }

        if (Schema::hasTable('tenants') && Schema::hasTable('users') && Schema::hasTable('company_payroll_settings') && ! Schema::hasTable('company_payroll_setting_events')) {
            Schema::create('company_payroll_setting_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('actor_id')->constrained('users');
                $table->string('action', 20);
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_payroll_setting_events');
        Schema::dropIfExists('company_payroll_settings');
    }
};
