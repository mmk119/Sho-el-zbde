<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Split the cost by pipeline step.
     *
     * cost_estimate stays as the total so nothing that already reads it breaks;
     * the two new columns say where the money actually went. Transcription is
     * billed per minute of audio, analysis per token, so a single number hides
     * which one is driving the bill.
     */
    public function up(): void
    {
        Schema::table('usage_logs', function (Blueprint $table) {
            $table->decimal('transcription_cost', 10, 6)->nullable()->after('cost_estimate');
            $table->decimal('analysis_cost', 10, 6)->nullable()->after('transcription_cost');
            $table->unsignedInteger('analysis_tokens')->nullable()->after('analysis_cost');
        });
    }

    public function down(): void
    {
        Schema::table('usage_logs', function (Blueprint $table) {
            $table->dropColumn(['transcription_cost', 'analysis_cost', 'analysis_tokens']);
        });
    }
};
