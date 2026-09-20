<?php

namespace Tests\Feature\Safety;

use App\Models\Digest;
use App\Models\Transcript;
use App\Models\UsageLog;
use App\Models\VoiceNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeExpiredNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function note(bool $expired, bool $withNormalized = true): VoiceNote
    {
        $note = VoiceNote::factory()->create([
            'expires_at' => $expired ? now()->subDay() : now()->addDays(10),
            'normalized_storage_path' => $withNormalized ? 'voice-notes/normalized/x.wav' : null,
        ]);

        Storage::put($note->storage_path, 'original audio');

        if ($withNormalized) {
            Storage::put($note->normalized_storage_path, 'normalized audio');
        }

        Transcript::create(['voice_note_id' => $note->id, 'full_text' => 'text', 'word_count' => 1]);
        UsageLog::create([
            'voice_note_id' => $note->id,
            'ip_hash' => str_repeat('a', 64),
            'duration_seconds' => 120,
            'cost_estimate' => 0.012,
        ]);

        return $note->fresh();
    }

    public function test_it_deletes_both_the_original_and_the_normalized_audio(): void
    {
        $note = $this->note(expired: true);

        $this->artisan('zbde:purge')->assertSuccessful();

        Storage::assertMissing($note->storage_path);
        Storage::assertMissing('voice-notes/normalized/x.wav');
    }

    public function test_it_deletes_the_note_and_everything_hanging_off_it(): void
    {
        $note = $this->note(expired: true);

        $this->artisan('zbde:purge');

        $this->assertDatabaseMissing('voice_notes', ['id' => $note->id]);
        $this->assertDatabaseMissing('transcripts', ['voice_note_id' => $note->id]);
    }

    public function test_it_deletes_the_digest_too(): void
    {
        $note = $this->note(expired: true);

        Digest::create([
            'voice_note_id' => $note->id,
            'summary' => ['x'], 'questions' => [], 'action_items' => [], 'notes' => [],
            'entities' => ['dates' => [], 'times' => [], 'amounts' => [], 'names' => [], 'places' => []],
            'urgency' => 'normal',
        ]);

        $this->artisan('zbde:purge');

        $this->assertDatabaseMissing('digests', ['voice_note_id' => $note->id]);
    }

    /**
     * The cost history outlives the content. The row carries a hashed IP, a
     * duration and a price - nothing anybody said.
     */
    public function test_it_keeps_the_usage_row_but_unlinks_it(): void
    {
        $note = $this->note(expired: true);

        $this->artisan('zbde:purge');

        $this->assertDatabaseCount('usage_logs', 1);
        $this->assertDatabaseHas('usage_logs', ['voice_note_id' => null, 'duration_seconds' => 120]);
    }

    public function test_it_leaves_notes_inside_the_window_completely_alone(): void
    {
        $keep = $this->note(expired: false);

        $this->artisan('zbde:purge');

        $this->assertDatabaseHas('voice_notes', ['id' => $keep->id]);
        Storage::assertExists($keep->storage_path);
    }

    public function test_it_purges_only_the_expired_ones_when_both_exist(): void
    {
        $keep = $this->note(expired: false);
        $drop = $this->note(expired: true);

        $this->artisan('zbde:purge');

        $this->assertDatabaseHas('voice_notes', ['id' => $keep->id]);
        $this->assertDatabaseMissing('voice_notes', ['id' => $drop->id]);
    }

    public function test_dry_run_reports_without_deleting_anything(): void
    {
        $note = $this->note(expired: true);

        $this->artisan('zbde:purge --dry-run')
            ->expectsOutputToContain('Would purge')
            ->assertSuccessful();

        $this->assertDatabaseHas('voice_notes', ['id' => $note->id]);
        Storage::assertExists($note->storage_path);
    }

    public function test_it_copes_with_a_note_whose_audio_is_already_gone(): void
    {
        $note = $this->note(expired: true);
        Storage::delete($note->storage_path);

        $this->artisan('zbde:purge')->assertSuccessful();

        $this->assertDatabaseMissing('voice_notes', ['id' => $note->id]);
    }

    public function test_it_says_so_when_there_is_nothing_to_do(): void
    {
        $this->artisan('zbde:purge')
            ->expectsOutputToContain('Nothing to purge')
            ->assertSuccessful();
    }
}
