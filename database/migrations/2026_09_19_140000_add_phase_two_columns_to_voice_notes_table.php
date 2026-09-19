<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_notes', function (Blueprint $table) {
            /*
             * Where NormalizeAudio puts the 16kHz mono wav. The original is kept
             * untouched because that is what the user plays back and what
             * duration_seconds was measured against, so the two files have to
             * coexist.
             */
            $table->string('normalized_storage_path')->nullable()->after('storage_path');

            /*
             * Names and places the uploader expects to hear, appended to the
             * mandatory default prompt. Optional for the user; the prompt itself
             * is never optional.
             */
            $table->string('prompt_hint', 500)->nullable()->after('language_hint');
        });
    }

    public function down(): void
    {
        Schema::table('voice_notes', function (Blueprint $table) {
            $table->dropColumn(['normalized_storage_path', 'prompt_hint']);
        });
    }
};
