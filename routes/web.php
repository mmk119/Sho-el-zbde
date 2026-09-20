<?php

use App\Livewire\UploadNote;
use App\Livewire\ViewNote;
use Illuminate\Support\Facades\Route;

Route::get('/', UploadNote::class)->name('upload');

/*
 * Short path on purpose - this is the link people paste to each other. The
 * token is 40 hex characters from a CSPRNG and is the only thing protecting a
 * private message, so these pages are marked noindex in the layout.
 */
Route::get('/r/{note}', ViewNote::class)->name('notes.show');
