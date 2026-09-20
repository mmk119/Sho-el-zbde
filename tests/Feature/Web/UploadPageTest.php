<?php

namespace Tests\Feature\Web;

use App\Contracts\AudioInspector;
use App\Jobs\AnalyzeTranscript;
use App\Jobs\NormalizeAudio;
use App\Jobs\NotifyReady;
use App\Jobs\TranscribeAudio;
use App\Livewire\UploadNote;
use App\Models\UsageLog;
use App\Models\VoiceNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class UploadPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Bus::fake();
    }

    private function fakeDuration(?int $seconds): void
    {
        $this->mock(AudioInspector::class, function ($mock) use ($seconds) {
            $mock->shouldReceive('durationSeconds')->andReturn($seconds);
        });
    }

    private function audio(int $sizeKb = 2048): UploadedFile
    {
        return UploadedFile::fake()->create('teta.m4a', $sizeKb, 'audio/x-m4a');
    }

    public function test_the_upload_page_loads(): void
    {
        $this->get(route('upload'))
            ->assertOk()
            ->assertSee('Sho el Zbde');
    }

    /** Share links must never be indexed - the token is the only protection. */
    public function test_pages_ask_not_to_be_indexed(): void
    {
        $this->get(route('upload'))->assertSee('noindex', false);
    }

    public function test_it_accepts_a_note_and_redirects_to_its_page(): void
    {
        $this->fakeDuration(540);

        Livewire::test(UploadNote::class)
            ->set('file', $this->audio())
            ->set('language_hint', 'ar')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseCount('voice_notes', 1);
    }

    public function test_it_dispatches_the_same_chain_as_the_api(): void
    {
        $this->fakeDuration(540);

        Livewire::test(UploadNote::class)
            ->set('file', $this->audio())
            ->call('save');

        Bus::assertChained([
            NormalizeAudio::class,
            TranscribeAudio::class,
            AnalyzeTranscript::class,
            NotifyReady::class,
        ]);
    }

    public function test_it_stores_the_optional_names_and_places_as_prompt_hint(): void
    {
        $this->fakeDuration(540);

        Livewire::test(UploadNote::class)
            ->set('file', $this->audio())
            ->set('prompt_hint', 'Teta Mariam, Jounieh')
            ->call('save');

        $this->assertSame('Teta Mariam, Jounieh', VoiceNote::first()->prompt_hint);
    }

    public function test_it_opens_a_usage_row_with_a_hashed_ip(): void
    {
        $this->fakeDuration(540);

        Livewire::test(UploadNote::class)
            ->set('file', $this->audio())
            ->call('save');

        $log = UsageLog::sole();

        $this->assertNotNull($log->ip_hash);
        $this->assertSame(64, strlen($log->ip_hash), 'the raw IP must never be stored');
    }

    public function test_it_refuses_audio_over_the_duration_limit_without_dispatching(): void
    {
        $this->fakeDuration(1260); // 21 minutes

        Livewire::test(UploadNote::class)
            ->set('file', $this->audio())
            ->call('save')
            ->assertHasErrors('file');

        Bus::assertNothingDispatched();
        $this->assertDatabaseCount('voice_notes', 0);
    }

    public function test_it_refuses_unreadable_audio(): void
    {
        $this->fakeDuration(null);

        Livewire::test(UploadNote::class)
            ->set('file', $this->audio())
            ->call('save')
            ->assertHasErrors('file');

        Bus::assertNothingDispatched();
    }

    public function test_it_refuses_a_file_that_is_not_audio(): void
    {
        $this->fakeDuration(60);

        Livewire::test(UploadNote::class)
            ->set('file', UploadedFile::fake()->create('taxes.pdf', 100, 'application/pdf'))
            ->call('save')
            ->assertHasErrors('file');

        Bus::assertNothingDispatched();
    }

    public function test_it_refuses_a_file_over_the_size_limit(): void
    {
        $this->fakeDuration(60);

        Livewire::test(UploadNote::class)
            ->set('file', $this->audio(101 * 1024))
            ->call('save')
            ->assertHasErrors('file');

        Bus::assertNothingDispatched();
    }
}
