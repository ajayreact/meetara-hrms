<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_legal_profiles') && ! Schema::hasColumn('company_legal_profiles', 'signature_enabled')) {
            Schema::table('company_legal_profiles', function (Blueprint $table) {
                $table->boolean('signature_enabled')->default(false);
                $table->boolean('signature_active')->default(false);
                $table->unsignedInteger('signature_version')->default(0);
                $table->unsignedBigInteger('signature_uploaded_by')->nullable();
                $table->timestamp('signature_uploaded_at')->nullable();
                $table->unsignedSmallInteger('signature_width')->default(140);
                $table->unsignedSmallInteger('signature_height')->default(48);
                $table->string('signature_align', 10)->default('left');
                $table->unsignedSmallInteger('signature_gap_before')->default(8);
                $table->unsignedSmallInteger('signature_gap_after')->default(6);
            });
        }

        if (Schema::hasTable('offer_letterheads') && ! Schema::hasColumn('offer_letterheads', 'page_width')) {
            Schema::table('offer_letterheads', function (Blueprint $table) {
                $table->decimal('page_width', 8, 2)->nullable();
                $table->decimal('page_height', 8, 2)->nullable();
                $table->unsignedSmallInteger('header_height')->nullable();
                $table->unsignedSmallInteger('footer_height')->nullable();
            });
        }

        if (Schema::hasTable('offer_letters') && ! Schema::hasColumn('offer_letters', 'working_hours')) {
            Schema::table('offer_letters', function (Blueprint $table) {
                $table->string('work_mode')->nullable();
                $table->string('working_hours')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('offer_letters') && Schema::hasColumn('offer_letters', 'working_hours')) {
            Schema::table('offer_letters', function (Blueprint $table) {
                $table->dropColumn(['work_mode', 'working_hours']);
            });
        }

        if (Schema::hasTable('offer_letterheads') && Schema::hasColumn('offer_letterheads', 'page_width')) {
            Schema::table('offer_letterheads', function (Blueprint $table) {
                $table->dropColumn(['page_width', 'page_height', 'header_height', 'footer_height']);
            });
        }

        if (Schema::hasTable('company_legal_profiles') && Schema::hasColumn('company_legal_profiles', 'signature_enabled')) {
            Schema::table('company_legal_profiles', function (Blueprint $table) {
                $table->dropColumn([
                    'signature_enabled', 'signature_active', 'signature_version', 'signature_uploaded_by',
                    'signature_uploaded_at', 'signature_width', 'signature_height', 'signature_align',
                    'signature_gap_before', 'signature_gap_after',
                ]);
            });
        }
    }
};
