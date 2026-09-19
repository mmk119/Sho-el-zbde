<?php

use App\Http\Controllers\VoiceNoteController;
use Illuminate\Support\Facades\Route;

/*
 * The token in these URLs is the entire access control model. It is 40 hex
 * characters from a CSPRNG - see VoiceNote::newToken().
 */

Route::post('/voice-notes', [VoiceNoteController::class, 'store'])
    ->name('voice-notes.store');

Route::get('/voice-notes/{voiceNote}', [VoiceNoteController::class, 'show'])
    ->name('voice-notes.show');

Route::get('/voice-notes/{voiceNote}/audio', [VoiceNoteController::class, 'audio'])
    ->name('voice-notes.audio');
