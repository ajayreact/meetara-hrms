<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('i983_student_certifications')) {
            return;
        }

        Schema::create('i983_student_certifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('link_id');
            $table->unsignedInteger('version');
            $table->string('printed_name', 160);
            $table->string('student_email', 191);
            $table->boolean('acknowledged');
            $table->char('statements_sha256', 64);
            $table->date('certified_on');
            $table->timestamp('certified_at');
            $table->string('timezone', 64);
            $table->string('signature_path');
            $table->char('signature_sha256', 64);
            $table->char('upload_sha256', 64);
            $table->timestamp('created_at')->nullable();
            $table->unique(['case_id', 'version']);
            $table->index('signature_sha256');
            $table->index('upload_sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('i983_student_certifications');
    }
};
