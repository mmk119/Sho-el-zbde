<?php

namespace App\Providers;

use App\Contracts\AudioInspector;
use App\Contracts\AudioNormalizer;
use App\Contracts\TranscriptionService;
use App\Services\Audio\FfmpegAudioNormalizer;
use App\Services\FfprobeAudioInspector;
use App\Services\Transcription\OpenAiTranscriptionService;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Every external tool sits behind a contract. Swapping hosted Whisper for a
 * self-hosted whisper.cpp is a change to the match arm below and nothing else.
 */
class AudioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AudioInspector::class, fn () => new FfprobeAudioInspector(
            config('shoelzbde.ffprobe_path')
        ));

        $this->app->bind(AudioNormalizer::class, fn ($app) => new FfmpegAudioNormalizer(
            ffmpegPath: config('shoelzbde.ffmpeg_path'),
            inspector: $app->make(AudioInspector::class),
            sampleRate: config('shoelzbde.target_sample_rate'),
            channels: config('shoelzbde.target_channels'),
            silenceDuration: (float) config('shoelzbde.normalization.silence_duration'),
            silenceThreshold: config('shoelzbde.normalization.silence_threshold'),
            timeoutSeconds: config('shoelzbde.normalization.timeout_seconds'),
        ));

        $this->app->bind(TranscriptionService::class, fn () => match (config('shoelzbde.transcription.driver')) {
            'openai' => new OpenAiTranscriptionService(
                apiKey: (string) config('shoelzbde.openai.key'),
                baseUrl: (string) config('shoelzbde.openai.base_url'),
                model: (string) config('shoelzbde.transcription.model'),
                timeoutSeconds: (int) config('shoelzbde.transcription.timeout_seconds'),
            ),
            default => throw new InvalidArgumentException(
                'Unknown transcription driver: '.config('shoelzbde.transcription.driver')
            ),
        });
    }
}
