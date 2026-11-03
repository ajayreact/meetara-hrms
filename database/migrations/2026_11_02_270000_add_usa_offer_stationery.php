<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('usa_offer_profiles') || Schema::hasColumn('usa_offer_profiles', 'letterhead_path')) {
            return;
        }

        Schema::table('usa_offer_profiles', function (Blueprint $table): void {
            $table->string('letterhead_path')->nullable();
            $table->string('letterhead_name')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('signature_name')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('usa_offer_profiles') || ! Schema::hasColumn('usa_offer_profiles', 'letterhead_path')) {
            return;
        }

        Schema::table('usa_offer_profiles', function (Blueprint $table): void {
            $table->dropColumn(['letterhead_path', 'letterhead_name', 'signature_path', 'signature_name']);
        });
    }
};
