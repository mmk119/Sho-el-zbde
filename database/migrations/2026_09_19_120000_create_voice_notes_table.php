<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Unguessable: this is the whole access control model for a share URL.
            $table->string('public_token', 64)->unique();

            $table->string('original_filename');
            $table->string('storage_path');

            // The ORIGINAL upload's duration. This is what the 20 minute limit is
            // checked against and what the user is shown.
            $table->unsignedInteger('duration_seconds')->nullable();

            // What NormalizeAudio actually produced after silence trimming, i.e.
            // what gets sent to Whisper. Null until that job runs. This is the
            // billable number and it is what feeds usage_logs.
            $table->unsignedInteger('normalized_duration_seconds')->nullable();

            $table->string('language_hint', 16)->nullable();
            $table->string('language_detected', 32)->nullable();

            $table->string('status', 16)->default('pending')->index();
            $table->text('error_message')->nullable();

            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_notes');
    }
};
