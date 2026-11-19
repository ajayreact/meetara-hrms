<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('id_card_company_profiles') && ! Schema::hasColumn('id_card_company_profiles', 'signature_path')) {
            Schema::table('id_card_company_profiles', function (Blueprint $table): void {
                $table->string('signature_path')->nullable()->after('logo_mime');
                $table->string('signature_mime', 40)->nullable()->after('signature_path');
            });
        }

        if (Schema::hasTable('standalone_id_cards') && ! Schema::hasColumn('standalone_id_cards', 'date_of_birth')) {
            Schema::table('standalone_id_cards', function (Blueprint $table): void {
                $table->date('date_of_birth')->nullable()->after('phone');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('id_card_company_profiles') && Schema::hasColumn('id_card_company_profiles', 'signature_path')) {
            Schema::table('id_card_company_profiles', function (Blueprint $table): void {
                $table->dropColumn(['signature_path', 'signature_mime']);
            });
        }

        if (Schema::hasTable('standalone_id_cards') && Schema::hasColumn('standalone_id_cards', 'date_of_birth')) {
            Schema::table('standalone_id_cards', function (Blueprint $table): void {
                $table->dropColumn('date_of_birth');
            });
        }
    }
};
