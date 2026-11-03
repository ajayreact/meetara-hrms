<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_legal_profiles') || Schema::hasColumn('company_legal_profiles', 'country')) {
            return;
        }

        Schema::table('company_legal_profiles', function (Blueprint $table) {
            $table->string('country', 10)->default('india');
        });

        Schema::table('company_legal_profiles', function (Blueprint $table) {
            $table->unique(['tenant_id', 'country']);
            $table->dropUnique(['tenant_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('company_legal_profiles') || ! Schema::hasColumn('company_legal_profiles', 'country')) {
            return;
        }

        Schema::table('company_legal_profiles', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'country']);
            $table->unique('tenant_id');
            $table->dropColumn('country');
        });
    }
};
