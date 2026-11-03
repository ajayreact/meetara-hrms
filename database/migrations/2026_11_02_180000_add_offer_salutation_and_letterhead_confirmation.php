<?php

use App\Services\Hrms\OfferLetterCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('offer_letters') && ! Schema::hasColumn('offer_letters', 'salutation')) {
            Schema::table('offer_letters', function (Blueprint $table): void {
                $table->string('salutation', 20)->nullable();
            });
        }

        if (Schema::hasTable('company_legal_profiles') && ! Schema::hasColumn('company_legal_profiles', 'letterhead_confirmed_by')) {
            Schema::table('company_legal_profiles', function (Blueprint $table): void {
                $table->unsignedBigInteger('letterhead_confirmed_by')->nullable();
                $table->timestamp('letterhead_confirmed_at')->nullable();
                $table->unsignedBigInteger('letterhead_confirmed_id')->nullable();
            });
        }

        if (Schema::hasTable('offer_letters')) {
            app(OfferLetterCatalog::class)->neutralizeDraftGreetings();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('company_legal_profiles') && Schema::hasColumn('company_legal_profiles', 'letterhead_confirmed_by')) {
            Schema::table('company_legal_profiles', function (Blueprint $table): void {
                $table->dropColumn(['letterhead_confirmed_by', 'letterhead_confirmed_at', 'letterhead_confirmed_id']);
            });
        }

        if (Schema::hasTable('offer_letters') && Schema::hasColumn('offer_letters', 'salutation')) {
            Schema::table('offer_letters', function (Blueprint $table): void {
                $table->dropColumn('salutation');
            });
        }
    }
};
