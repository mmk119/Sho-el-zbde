<?php

use App\Console\Commands\PurgeExpiredNotes;
use Illuminate\Support\Facades\Schedule;

/*
 * Audio is deleted after the retention window. Daily is frequent enough: the
 * result page already refuses to serve an expired note, so this is about
 * reclaiming disk and honouring the promise in the footer, not about access
 * control.
 */
Schedule::command(PurgeExpiredNotes::class)
    ->dailyAt('03:30')
    ->onOneServer()
    ->withoutOverlapping();
