<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('offer_letters') && ! Schema::hasColumn('offer_letters', 'candidate_address')) {
            Schema::table('offer_letters', function (Blueprint $table): void {
                $table->text('candidate_address')->nullable();
            });
        }

        if (Schema::hasTable('usa_offers') && ! Schema::hasColumn('usa_offers', 'employment_type')) {
            Schema::table('usa_offers', function (Blueprint $table): void {
                $table->string('employment_type', 80)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('offer_letters') && Schema::hasColumn('offer_letters', 'candidate_address')) {
            Schema::table('offer_letters', function (Blueprint $table): void {
                $table->dropColumn('candidate_address');
            });
        }

        if (Schema::hasTable('usa_offers') && Schema::hasColumn('usa_offers', 'employment_type')) {
            Schema::table('usa_offers', function (Blueprint $table): void {
                $table->dropColumn('employment_type');
            });
        }
    }
};
