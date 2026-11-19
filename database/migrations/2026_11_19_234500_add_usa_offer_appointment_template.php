<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('usa_offer_profiles') || Schema::hasColumn('usa_offer_profiles', 'appointment_template')) {
            return;
        }

        Schema::table('usa_offer_profiles', function (Blueprint $table): void {
            $table->json('appointment_template')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('usa_offer_profiles') || ! Schema::hasColumn('usa_offer_profiles', 'appointment_template')) {
            return;
        }

        Schema::table('usa_offer_profiles', function (Blueprint $table): void {
            $table->dropColumn('appointment_template');
        });
    }
};
