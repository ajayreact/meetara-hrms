<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('i983_cases') || Schema::hasTable('i983_corrections')) {
            return;
        }

        Schema::create('i983_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('case_id')->constrained('i983_cases');
            $table->text('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->timestamp('requested_at');
            $table->timestamp('resolved_at')->nullable();
            $table->index(['case_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('i983_corrections');
    }
};
