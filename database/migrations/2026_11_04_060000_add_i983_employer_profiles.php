<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('i983_employer_profiles')) {
            return;
        }

        Schema::create('i983_employer_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants');
            $table->string('legal_name', 191);
            $table->string('website', 191)->nullable();
            $table->string('street', 191);
            $table->string('suite', 40)->nullable();
            $table->string('city', 80);
            $table->string('state', 2);
            $table->string('zip', 10);
            $table->text('ein_ciphertext');
            $table->unsignedInteger('employee_count');
            $table->string('naics', 10);
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('i983_employer_profiles');
    }
};
