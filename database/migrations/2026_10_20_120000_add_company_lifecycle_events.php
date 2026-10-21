<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenants') && Schema::hasTable('users') && ! Schema::hasTable('company_lifecycle_events')) {
            Schema::create('company_lifecycle_events', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants');
                $table->foreignId('actor_id')->constrained('users');
                $table->string('action', 20);
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_lifecycle_events');
    }
};
