<?php

namespace App\Services\Audio;

use App\Contracts\AudioInspector;
use App\Contracts\AudioNormalizer;
use App\Exceptions\AudioNormalizationFailed;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class FfmpegAudioNormalizer implements AudioNormalizer
{
    public function __construct(
        private readonly string $ffmpegPath,
        private readonly AudioInspector $inspector,
        private readonly int $sampleRate,
        private readonly int $channels,
        private readonly float $silenceDuration,
        private readonly string $silenceThreshold,
        private readonly int $timeoutSeconds,
    ) {
    }

    public function normalize(string $sourceAbsolutePath, string $targetAbsolutePath): NormalizedAudio
    {
        if (! is_file($sourceAbsolutePath)) {
            throw new AudioNormalizationFailed('Source audio file is missing.');
        }

        $directory = dirname($targetAbsolutePath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new AudioNormalizationFailed('Could not create the output directory.');
        }

        $process = new Process([
            $this->ffmpegPath,
            '-hide_banner',
            '-loglevel', 'error',
            '-y',
            '-i', $sourceAbsolutePath,
            '-ac', (string) $this->channels,
            '-ar', (string) $this->sampleRate,
            '-c:a', 'pcm_s16le',
            '-af', $this->silenceFilter(),
            $targetAbsolutePath,
        ]);

        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new AudioNormalizationFailed('ffmpeg timed out.');
        }

        if (! $process->isSuccessful() || ! is_file($targetAbsolutePath)) {
            throw new AudioNormalizationFailed('ffmpeg exited with an error.');
        }

        $duration = $this->inspector->durationSeconds($targetAbsolutePath);

        if ($duration === null) {
            throw new AudioNormalizationFailed('Could not measure the normalized audio.');
        }

        return new NormalizedAudio(
            absolutePath: $targetAbsolutePath,
            durationSeconds: $duration,
            bytes: (int) filesize($targetAbsolutePath),
        );
    }

    /**
     * The exact chain transcribe-test.php used in Phase 0. Keeping these
     * identical is what makes the Phase 0 measurements mean anything.
     */
    private function silenceFilter(): string
    {
        return sprintf(
            'silenceremove=stop_periods=-1:stop_duration=%s:stop_threshold=%s',
            $this->silenceDuration,
            $this->silenceThreshold,
        );
    }
}
