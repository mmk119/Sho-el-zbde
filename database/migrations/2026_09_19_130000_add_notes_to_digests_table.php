<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where "I could not make this out" goes.
     *
     * Phase 0 found that transcripts carry real errors on proper nouns and
     * code-switched words. The analysis prompt is required to flag unclear
     * passages rather than invent meaning - but an instruction to flag is
     * useless without somewhere for the flag to land. This is that place.
     *
     * Empty array when the transcript was clean. Never null: the frontend
     * should be able to count() it without a guard.
     */
    public function up(): void
    {
        Schema::table('digests', function (Blueprint $table) {
            $table->json('notes')->nullable()->after('action_items');
        });
    }

    public function down(): void
    {
        Schema::table('digests', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
