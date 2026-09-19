<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voice_note_id')->constrained()->cascadeOnDelete();
            $table->longText('full_text');

            // Stored because it is cheap, but nothing may depend on these
            // boundaries - see the Phase 0 findings in CLAUDE.md. The same audio
            // produced 66 segments unprompted and 9 prompted.
            $table->json('segments')->nullable();

            $table->unsignedInteger('word_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcripts');
    }
};
