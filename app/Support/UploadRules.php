<?php

namespace App\Support;

use App\Contracts\AudioInspector;

/**
 * The upload limits, in one place, so the API and the web form enforce the same
 * thing. Both limits are checked against the ORIGINAL upload before any job is
 * dispatched - silence trimming shortens audio, so checking later would let
 * over-length notes through.
 */
final class UploadRules
{
    public static function maxBytes(): int
    {
        return (int) config('shoelzbde.max_upload_mb') * 1024 * 1024;
    }

    public static function maxKilobytes(): int
    {
        return (int) config('shoelzbde.max_upload_mb') * 1024;
    }

    public static function maxDurationSeconds(): int
    {
        return (int) config('shoelzbde.max_duration_seconds');
    }

    public static function maxDurationMinutes(): int
    {
        return (int) (self::maxDurationSeconds() / 60);
    }

    /** @return string[] */
    public static function acceptedMimes(): array
    {
        return config('shoelzbde.accepted_mimes');
    }

    /** What the file picker should offer, and what we tell the user we accept. */
    public static function acceptAttribute(): string
    {
        return 'audio/*,video/mp4,video/webm,.m4a,.opus,.amr,.3gp';
    }

    /** @return string[] human-facing list of formats */
    public static function friendlyFormats(): array
    {
        return ['mp3', 'm4a', 'wav', 'ogg', 'opus', 'aac', 'flac', 'amr', 'webm', 'mp4'];
    }

    /**
     * Probe the original upload. Returns [durationSeconds, errorMessage]; the
     * error is already phrased for a human.
     *
     * @return array{0: ?int, 1: ?string}
     */
    public static function inspect(string $absolutePath): array
    {
        $seconds = app(AudioInspector::class)->durationSeconds($absolutePath);

        if ($seconds === null) {
            return [null, 'We could not read this audio file. It may be corrupt.'];
        }

        if ($seconds > self::maxDurationSeconds()) {
            return [null, sprintf(
                'This note is %d minutes long. The limit is %d minutes.',
                (int) ceil($seconds / 60),
                self::maxDurationMinutes(),
            )];
        }

        return [$seconds, null];
    }
}
