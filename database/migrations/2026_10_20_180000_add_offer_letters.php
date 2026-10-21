<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasTable('users') || Schema::hasTable('offer_letters')) {
            return;
        }

        Schema::create('offer_letters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('position_code', 40);
            $table->string('employee_name');
            $table->decimal('monthly_salary', 12, 2);
            $table->decimal('annual_ctc', 12, 2);
            $table->date('joining_date');
            $table->unsignedTinyInteger('notice_months')->default(3);
            $table->string('hr_name');
            $table->string('hr_signature');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->text('preview_body')->nullable();
            $table->text('issued_body')->nullable();
            $table->text('issued_values')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('replaces_letter_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'employee_id']);

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->engine = 'InnoDB';
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_letters');
    }
};
