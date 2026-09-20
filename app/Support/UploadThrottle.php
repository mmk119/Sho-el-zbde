<?php

namespace App\Support;

use App\Models\UsageLog;

/**
 * Per-IP limit on anonymous uploads.
 *
 * Counts the usage_logs rows we already write at upload time rather than
 * keeping a second tally in the cache. One source of truth, and it survives a
 * cache flush - which matters, because a throttle you can clear by restarting
 * the cache is not really a throttle.
 *
 * Signed-in users are not throttled here: they are identifiable, and the whole
 * point of the IP hash is to have something to count for people who are not.
 */
final class UploadThrottle
{
    public static function perHour(): int
    {
        return (int) config('shoelzbde.anon_uploads_per_hour');
    }

    public static function used(?string $ip, ?int $userId = null): int
    {
        if ($userId !== null) {
            return 0;
        }

        $hash = UsageLog::hashIp($ip);

        if ($hash === null) {
            return 0;
        }

        return UsageLog::where('ip_hash', $hash)
            ->where('created_at', '>=', now()->subHour())
            ->count();
    }

    /**
     * Zero or less turns the throttle off entirely.
     *
     * Worth being explicit about, because the obvious reading of
     * ANON_UPLOADS_PER_HOUR=0 is "no uploads allowed" - and the comparison
     * below would have delivered exactly that, locking everyone out of a
     * setting that looks like it disables a limit.
     */
    public static function enabled(): bool
    {
        return self::perHour() > 0;
    }

    public static function exceeded(?string $ip, ?int $userId = null): bool
    {
        if (! self::enabled()) {
            return false;
        }

        return self::used($ip, $userId) >= self::perHour();
    }

    /**
     * Seconds until the oldest upload in the window falls out of it, so the
     * caller can send an honest Retry-After instead of a guess.
     */
    public static function retryAfter(?string $ip, ?int $userId = null): int
    {
        $hash = UsageLog::hashIp($ip);

        if ($userId !== null || $hash === null) {
            return 0;
        }

        $oldest = UsageLog::where('ip_hash', $hash)
            ->where('created_at', '>=', now()->subHour())
            ->orderBy('created_at')
            ->value('created_at');

        if ($oldest === null) {
            return 0;
        }

        return max(1, (int) ceil(now()->diffInSeconds($oldest->copy()->addHour(), absolute: false)));
    }

    /** Said plainly, with the wait in minutes, not a generic "too many requests". */
    public static function message(?string $ip, ?int $userId = null): string
    {
        $minutes = (int) ceil(self::retryAfter($ip, $userId) / 60);

        return sprintf(
            "You've uploaded %d notes in the last hour, which is the limit. %s",
            self::perHour(),
            $minutes > 1
                ? "Try again in about {$minutes} minutes."
                : 'Try again in a minute.',
        );
    }
}
