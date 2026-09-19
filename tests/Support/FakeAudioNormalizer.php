<?php

namespace Tests\Support;

use App\Contracts\AudioNormalizer;
use App\Exceptions\AudioNormalizationFailed;
use App\Services\Audio\NormalizedAudio;

class FakeAudioNormalizer implements AudioNormalizer
{
    public ?string $sawSource = null;

    public function __construct(
        private readonly int $durationSeconds = 120,
        private readonly bool $shouldFail = false,
    ) {
    }

    public function normalize(string $sourceAbsolutePath, string $targetAbsolutePath): NormalizedAudio
    {
        $this->sawSource = $sourceAbsolutePath;

        if ($this->shouldFail) {
            throw new AudioNormalizationFailed('ffmpeg exited with an error.');
        }

        @mkdir(dirname($targetAbsolutePath), 0755, true);
        file_put_contents($targetAbsolutePath, 'fake wav bytes');

        return new NormalizedAudio($targetAbsolutePath, $this->durationSeconds, 14);
    }
}
