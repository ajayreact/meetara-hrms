<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants') || Schema::hasColumn('tenants', 'offer_approval_required')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('offer_approval_required')->default(true);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasColumn('tenants', 'offer_approval_required')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('offer_approval_required');
        });
    }
};
