<?php

namespace Tests\Feature\Jobs;

use App\Contracts\AnalysisService;
use App\Enums\Urgency;
use App\Enums\VoiceNoteStatus;
use App\Exceptions\AnalysisRateLimited;
use App\Exceptions\AnalysisRejected;
use App\Jobs\AnalyzeTranscript;
use App\Models\Transcript;
use App\Models\UsageLog;
use App\Models\VoiceNote;
use Tests\Support\FakeAnalysisService;

class AnalyzeTranscriptTest extends JobChainTestCase
{
    protected function jobClass(): string
    {
        return AnalyzeTranscript::class;
    }

    protected function startingStatus(): VoiceNoteStatus
    {
        return VoiceNoteStatus::Analyzing;
    }

    /** This job does its work but leaves the note analyzing; NotifyReady closes it. */
    protected function expectedStatus(): VoiceNoteStatus
    {
        return VoiceNoteStatus::Analyzing;
    }

    protected function prepare(VoiceNote $note): VoiceNote
    {
        Transcript::create([
            'voice_note_id' => $note->id,
            'full_text' => 'انا عشت الحرب بلبنان كلها',
            'word_count' => 5,
        ]);

        return $note;
    }

    private function bind(FakeAnalysisService $fake): FakeAnalysisService
    {
        $this->app->bind(AnalysisService::class, fn () => $fake);

        return $fake;
    }

    public function test_it_stores_the_digest(): void
    {
        $note = $this->makeNote(VoiceNoteStatus::Analyzing);

        $this->runJob($note);

        $digest = $note->fresh()->digest;

        $this->assertNotNull($digest);
        $this->assertSame(['بيحكي عن الحرب'], $digest->summary);
        $this->assertSame(['بيروت'], $digest->entities['places']);
        $this->assertSame(Urgency::Normal, $digest->urgency);
        $this->assertSame([], $digest->notes);
    }

    public function test_it_records_the_model_and_token_count(): void
    {
        $note = $this->makeNote(VoiceNoteStatus::Analyzing);

        $this->runJob($note);

        $digest = $note->fresh()->digest;

        $this->assertSame('gpt-4o-test', $digest->model_used);
        $this->assertSame(1200, $digest->tokens_used);
    }

    public function test_it_retries_once_with_a_stricter_instruction_then_succeeds(): void
    {
        $fake = $this->bind(new FakeAnalysisService([
            FakeAnalysisService::result(['summary' => ['incomplete']]),   // schema failure
            FakeAnalysisService::result(FakeAnalysisService::valid()),    // fixed
        ]));

        $note = $this->makeNote(VoiceNoteStatus::Analyzing);

        $this->runJob($note);

        $this->assertSame(2, $fake->calls);
        $this->assertSame([false, true], $fake->sawStricter, 'the second pass must ask for stricter output');
        $this->assertNotNull($note->fresh()->digest);
        $this->assertSame(VoiceNoteStatus::Analyzing, $note->fresh()->status);
    }

    public function test_it_gives_up_after_two_failures_and_says_the_transcript_survives(): void
    {
        $fake = $this->bind(new FakeAnalysisService([
            FakeAnalysisService::result(['summary' => ['bad']]),
            FakeAnalysisService::result(['summary' => ['still bad']]),
        ]));

        $note = $this->makeNote(VoiceNoteStatus::Analyzing);

        $this->runJob($note);

        $fresh = $note->fresh();

        $this->assertSame(2, $fake->calls, 'exactly one retry, not a loop');
        $this->assertSame(VoiceNoteStatus::Failed, $fresh->status);
        $this->assertNull($fresh->digest);
        $this->assertStringContainsString('transcript is still available', $fresh->error_message);
    }

    public function test_unparseable_json_also_triggers_the_single_retry(): void
    {
        $fake = $this->bind(new FakeAnalysisService([
            FakeAnalysisService::result(null, unparseable: true),
            FakeAnalysisService::result(FakeAnalysisService::valid()),
        ]));

        $note = $this->makeNote(VoiceNoteStatus::Analyzing);

        $this->runJob($note);

        $this->assertSame(2, $fake->calls);
        $this->assertNotNull($note->fresh()->digest);
    }

    /** We are charged for a rejected attempt, so it has to show up in the bill. */
    public function test_it_bills_for_both_attempts_when_the_first_is_rejected(): void
    {
        config([
            'shoelzbde.analysis.cost_per_million_input_usd' => 2.50,
            'shoelzbde.analysis.cost_per_million_output_usd' => 10.00,
        ]);

        $this->bind(new FakeAnalysisService([
            FakeAnalysisService::result(['summary' => ['bad']]),
            FakeAnalysisService::result(FakeAnalysisService::valid()),
        ]));

        $note = $this->makeNote(VoiceNoteStatus::Analyzing);

        $this->runJob($note);

        $log = UsageLog::where('voice_note_id', $note->id)->sole();

        $this->assertSame(2400, $log->analysis_tokens, 'both attempts counted');
        $this->assertGreaterThan(0, (float) $log->analysis_cost);
    }

    public function test_analysis_cost_is_kept_separate_from_transcription_cost(): void
    {
        $note = $this->makeNote(VoiceNoteStatus::Analyzing);

        UsageLog::create([
            'voice_note_id' => $note->id,
            'duration_seconds' => 180,
            'transcription_cost' => 0.018,
            'cost_estimate' => 0.018,
        ]);

        $this->runJob($note);

        $log = UsageLog::where('voice_note_id', $note->id)->sole();

        $this->assertEqualsWithDelta(0.018, (float) $log->transcription_cost, 0.000001);
        $this->assertGreaterThan(0, (float) $log->analysis_cost);
        $this->assertEqualsWithDelta(
            (float) $log->transcription_cost + (float) $log->analysis_cost,
            (float) $log->cost_estimate,
            0.000001,
            'cost_estimate must stay the total of the steps',
        );
    }

    public function test_a_rate_limit_releases_instead_of_failing(): void
    {
        $this->bind(new FakeAnalysisService(throws: new AnalysisRateLimited(45)));

        $note = $this->makeNote(VoiceNoteStatus::Analyzing);

        $job = new AnalyzeTranscript($note);
        $job->job = $this->createMock(\Illuminate\Contracts\Queue\Job::class);
        $job->job->expects($this->once())->method('release')->with(45);

        $this->app->call([$job, 'handle']);

        $this->assertSame(VoiceNoteStatus::Analyzing, $note->fresh()->status);
    }

    public function test_a_permanent_rejection_fails_the_note_readably(): void
    {
        $this->bind(new FakeAnalysisService(throws: new AnalysisRejected('bad key')));

        $note = $this->makeNote(VoiceNoteStatus::Analyzing);

        $job = new AnalyzeTranscript($note);
        $job->job = $this->createMock(\Illuminate\Contracts\Queue\Job::class);

        $this->app->call([$job, 'handle']);

        $fresh = $note->fresh();

        $this->assertSame(VoiceNoteStatus::Failed, $fresh->status);
        $this->assertStringNotContainsString('bad key', $fresh->error_message);
    }

    public function test_it_fails_readably_when_there_is_no_transcript(): void
    {
        $note = VoiceNote::factory()->status(VoiceNoteStatus::Analyzing)->create();

        $this->runJob($note);

        $this->assertSame(VoiceNoteStatus::Failed, $note->fresh()->status);
    }

    /** Without this the model translates Arabic speech into an English summary. */
    public function test_it_tells_the_analyst_which_language_the_speaker_used(): void
    {
        $fake = $this->bind(new FakeAnalysisService());

        $note = $this->makeNote(VoiceNoteStatus::Analyzing, ['language_detected' => 'arabic']);

        $this->runJob($note);

        $this->assertSame('arabic', $fake->sawLanguage);
    }

    public function test_it_keeps_the_notes_the_model_reported(): void
    {
        $this->bind(new FakeAnalysisService([
            FakeAnalysisService::result(FakeAnalysisService::valid([
                'notes' => ['A passage around the middle was too garbled to summarise.'],
            ])),
        ]));

        $note = $this->makeNote(VoiceNoteStatus::Analyzing);

        $this->runJob($note);

        $this->assertCount(1, $note->fresh()->digest->notes);
    }
}
