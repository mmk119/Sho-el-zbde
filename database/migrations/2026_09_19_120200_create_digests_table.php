<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voice_note_id')->constrained()->cascadeOnDelete();
            $table->json('summary')->nullable();
            $table->json('questions')->nullable();
            $table->json('entities')->nullable();
            $table->json('action_items')->nullable();
            $table->string('urgency', 8)->default('normal');
            $table->string('model_used')->nullable();
            $table->unsignedInteger('tokens_used')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digests');
    }
};
