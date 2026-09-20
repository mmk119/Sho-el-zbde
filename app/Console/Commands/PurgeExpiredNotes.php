<?php

namespace App\Console\Commands;

use App\Models\UsageLog;
use App\Models\VoiceNote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes voice notes past their retention date, audio first.
 *
 * Runs daily from the scheduler, and is safe to run by hand.
 *
 * usage_logs rows deliberately survive: the migration nulls voice_note_id
 * rather than cascading, so the cost history and the per-IP throttle count
 * remain intact after the content is gone. They carry no transcript and no
 * filename - only a hashed IP, a duration and a price.
 */
class PurgeExpiredNotes extends Command
{
    protected $signature = 'zbde:purge {--dry-run : List what would be deleted without deleting it}';

    protected $description = 'Delete voice notes, and their audio, past the retention window';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $expired = VoiceNote::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        if ($expired->isEmpty()) {
            $this->info('Nothing to purge.');

            return self::SUCCESS;
        }

        $files = 0;
        $bytes = 0;

        foreach ($expired as $note) {
            foreach ($this->filesFor($note) as $path) {
                if (! Storage::exists($path)) {
                    continue;
                }

                $bytes += Storage::size($path);
                $files++;

                if (! $dryRun) {
                    Storage::delete($path);
                }
            }

            if (! $dryRun) {
                // Keep the billing row, drop its link to content that no
                // longer exists.
                UsageLog::where('voice_note_id', $note->id)->update(['voice_note_id' => null]);

                // Cascades to transcripts and digests.
                $note->delete();
            }
        }

        $this->info(sprintf(
            '%s %d note%s and %d file%s (%s).',
            $dryRun ? 'Would purge' : 'Purged',
            $expired->count(),
            $expired->count() === 1 ? '' : 's',
            $files,
            $files === 1 ? '' : 's',
            $this->humanBytes($bytes),
        ));

        return self::SUCCESS;
    }

    /** @return string[] both the original upload and the normalized wav */
    private function filesFor(VoiceNote $note): array
    {
        return array_filter([
            $note->storage_path,
            $note->normalized_storage_path,
        ]);
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $i = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $size, $units[$i]);
    }
}
