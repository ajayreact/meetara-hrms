<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lead_sources')) {
            Schema::create('lead_sources', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('name', 120);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'name'], 'lead_sources_tenant_name_unique');
                $table->index(['tenant_id', 'is_active'], 'lead_sources_tenant_active_index');
            });
        }

        if (Schema::hasTable('leads') && ! Schema::hasColumn('leads', 'lead_source_id')) {
            Schema::table('leads', function (Blueprint $table): void {
                $table->unsignedBigInteger('lead_source_id')->nullable()->after('import_batch_id');
                $table->string('source_name', 120)->nullable()->after('lead_source_id');
                $table->index(['tenant_id', 'lead_source_id'], 'leads_tenant_source_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leads') && Schema::hasColumn('leads', 'lead_source_id')) {
            Schema::table('leads', function (Blueprint $table): void {
                $table->dropIndex('leads_tenant_source_index');
                $table->dropColumn(['lead_source_id', 'source_name']);
            });
        }

        Schema::dropIfExists('lead_sources');
    }
};
