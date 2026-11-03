<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_documents') && ! Schema::hasColumn('employee_documents', 'reference')) {
            Schema::table('employee_documents', function (Blueprint $table): void {
                $table->string('reference', 80)->nullable();
                $table->text('description')->nullable();
            });
        }

        if (! Schema::hasTable('document_category_settings')) {
            Schema::create('document_category_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('category_key', 40);
                $table->string('name', 80);
                $table->string('group_key', 30);
                $table->boolean('is_required')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['tenant_id', 'category_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_category_settings');

        if (Schema::hasTable('employee_documents') && Schema::hasColumn('employee_documents', 'reference')) {
            Schema::table('employee_documents', function (Blueprint $table): void {
                $table->dropColumn(['reference', 'description']);
            });
        }
    }
};
