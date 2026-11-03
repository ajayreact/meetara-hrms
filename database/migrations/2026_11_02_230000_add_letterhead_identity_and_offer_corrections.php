<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('offer_letterheads') && ! Schema::hasColumn('offer_letterheads', 'printed_legal_name')) {
            Schema::table('offer_letterheads', function (Blueprint $table): void {
                $table->string('printed_legal_name')->nullable();
                $table->text('printed_address')->nullable();
            });
        }

        if (! Schema::hasTable('offer_letter_corrections')) {
            Schema::create('offer_letter_corrections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('offer_letter_id');
                $table->unsignedBigInteger('actor_id');
                $table->string('reason', 500);
                $table->json('previous_values')->nullable();
                $table->json('updated_values')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'offer_letter_id']);
            });
        }

        if (Schema::hasTable('offer_letterheads') && Schema::hasColumn('offer_letterheads', 'printed_legal_name')) {
            DB::table('offer_letterheads')
                ->where('original_name', 'Letter Head-India - Empty.png')
                ->where(function ($query): void {
                    $query->whereNull('printed_legal_name')->orWhere('printed_legal_name', '');
                })
                ->update([
                    'printed_legal_name' => 'VELTRIX DIGITAL SOLUTIONS INC.',
                    'printed_address' => 'Suite-1101, 11th Floor, B-Block, Asian Suncity, Kondapur, HYD',
                ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_letter_corrections');

        if (Schema::hasTable('offer_letterheads') && Schema::hasColumn('offer_letterheads', 'printed_legal_name')) {
            Schema::table('offer_letterheads', function (Blueprint $table): void {
                $table->dropColumn(['printed_legal_name', 'printed_address']);
            });
        }
    }
};
