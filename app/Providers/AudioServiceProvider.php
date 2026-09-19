<?php

namespace App\Providers;

use App\Contracts\AudioInspector;
use App\Services\FfprobeAudioInspector;
use Illuminate\Support\ServiceProvider;

/**
 * Every external tool sits behind a contract. Swapping ffprobe for something
 * else is a one line change here.
 */
class AudioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AudioInspector::class, fn () => new FfprobeAudioInspector(
            config('shoelzbde.ffprobe_path')
        ));
    }
}
