<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('i983_evaluations')) {
            return;
        }

        $duplicate = DB::table('i983_evaluations')
            ->select('case_id', 'kind')
            ->groupBy('case_id', 'kind')
            ->havingRaw('count(*) > 1')
            ->exists();

        if ($duplicate) {
            throw new RuntimeException('i983_evaluations already has more than one row for a case and kind. The unique index was not added.');
        }

        Schema::table('i983_evaluations', function (Blueprint $table): void {
            if (! Schema::hasColumn('i983_evaluations', 'student_printed_name')) {
                $table->string('student_printed_name')->nullable();
            }
            if (! Schema::hasColumn('i983_evaluations', 'employer_printed_name')) {
                $table->string('employer_printed_name')->nullable();
            }
            if (! Schema::hasColumn('i983_evaluations', 'employer_title')) {
                $table->string('employer_title')->nullable();
            }
            if (! Schema::hasColumn('i983_evaluations', 'document_id')) {
                $table->unsignedBigInteger('document_id')->nullable();
            }
            if (! Schema::hasColumn('i983_evaluations', 'status')) {
                $table->string('status', 32)->nullable();
            }
        });

        $indexes = collect(Schema::getIndexes('i983_evaluations'))->pluck('name');

        if (! $indexes->contains('i983_evaluations_case_kind_unique')) {
            Schema::table('i983_evaluations', function (Blueprint $table): void {
                $table->unique(['case_id', 'kind'], 'i983_evaluations_case_kind_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('i983_evaluations')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('i983_evaluations'))->pluck('name');

        Schema::table('i983_evaluations', function (Blueprint $table) use ($indexes): void {
            if ($indexes->contains('i983_evaluations_case_kind_unique')) {
                $table->dropUnique('i983_evaluations_case_kind_unique');
            }
            foreach (['student_printed_name', 'employer_printed_name', 'employer_title', 'document_id', 'status'] as $column) {
                if (Schema::hasColumn('i983_evaluations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
