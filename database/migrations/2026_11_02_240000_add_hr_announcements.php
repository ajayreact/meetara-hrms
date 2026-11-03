<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_announcements')) {
            return;
        }

        Schema::create('hr_announcements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('title');
            $table->text('description');
            $table->string('category', 30);
            $table->string('visibility', 20)->default('company');
            $table->string('status', 20)->default('draft');
            $table->timestamp('publish_at')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'publish_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_announcements');
    }
};
