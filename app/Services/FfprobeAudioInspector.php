<?php

namespace App\Services;

use App\Contracts\AudioInspector;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class FfprobeAudioInspector implements AudioInspector
{
    public function __construct(private readonly string $ffprobePath)
    {
    }

    public function durationSeconds(string $absolutePath): ?int
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $process = new Process([
            $this->ffprobePath,
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $absolutePath,
        ]);

        $process->setTimeout(30);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());

        return is_numeric($output) ? (int) round((float) $output) : null;
    }
}
