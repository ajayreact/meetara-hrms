<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('id_card_company_profiles') && ! Schema::hasColumn('id_card_company_profiles', 'white_logo_path')) {
            Schema::table('id_card_company_profiles', function (Blueprint $table): void {
                $table->string('white_logo_path')->nullable()->after('logo_mime');
                $table->string('white_logo_mime', 40)->nullable()->after('white_logo_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('id_card_company_profiles') && Schema::hasColumn('id_card_company_profiles', 'white_logo_path')) {
            Schema::table('id_card_company_profiles', function (Blueprint $table): void {
                $table->dropColumn(['white_logo_path', 'white_logo_mime']);
            });
        }
    }
};
