<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants') || Schema::hasColumn('tenants', 'legal_name')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasColumn('tenants', 'legal_name')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('legal_name');
        });
    }
};
