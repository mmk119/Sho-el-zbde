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
    /**
     * The limit we can actually honour, which is the smallest of three numbers:
     * our own setting, PHP's upload_max_filesize, and PHP's post_max_size.
     *
     * PHP enforces its two before Laravel sees the request at all - the upload
     * arrives empty and the only thing a framework can say is "the file failed
     * to upload", which tells the user nothing. Advertising a limit larger than
     * PHP allows turns a clear "too big" message into a mystery, so the smallest
     * number wins everywhere: validation, the error text, and the hint under the
     * drop zone.
     */
    public static function maxBytes(): int
    {
        $ours = (int) config('shoelzbde.max_upload_mb') * 1024 * 1024;

        $limits = array_filter([
            $ours,
            self::iniBytes('upload_max_filesize'),
            self::iniBytes('post_max_size'),
        ], fn ($bytes) => $bytes > 0);

        return (int) min($limits);
    }

    public static function maxKilobytes(): int
    {
        return intdiv(self::maxBytes(), 1024);
    }

    public static function maxMegabytes(): int
    {
        return intdiv(self::maxBytes(), 1024 * 1024);
    }

    /** True when PHP, not us, is the binding constraint - worth saying out loud. */
    public static function phpIsTheBottleneck(): bool
    {
        return self::maxBytes() < (int) config('shoelzbde.max_upload_mb') * 1024 * 1024;
    }

    /** Turns PHP's "8M" / "512K" / "-1" shorthand into bytes. -1 means no limit. */
    private static function iniBytes(string $key): int
    {
        $raw = trim((string) ini_get($key));

        if ($raw === '' || $raw === '-1') {
            return 0;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
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
