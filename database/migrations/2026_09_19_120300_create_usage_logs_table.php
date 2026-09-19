<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Hashed, never the raw IP - these are private messages and the
            // uploader is usually anonymous.
            $table->string('ip_hash', 64)->nullable()->index();

            $table->foreignId('voice_note_id')->nullable()->constrained()->nullOnDelete();

            // The NORMALIZED duration. We are billed on what we sent, not on what
            // was uploaded.
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->decimal('cost_estimate', 10, 6)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_logs');
    }
};
