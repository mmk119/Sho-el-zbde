<?php

namespace Tests\Feature\Safety;

use App\Contracts\AudioInspector;
use App\Livewire\UploadNote;
use App\Models\UsageLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class UploadThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Bus::fake();
        config(['shoelzbde.anon_uploads_per_hour' => 3]);

        $this->mock(AudioInspector::class, fn ($mock) => $mock->shouldReceive('durationSeconds')->andReturn(120));
    }

    private function audio(): UploadedFile
    {
        return UploadedFile::fake()->create('teta.m4a', 512, 'audio/x-m4a');
    }

    /** Fill the window using the same rows a real upload would write. */
    private function seedUploads(int $count, string $ip = '127.0.0.1', $age = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            UsageLog::create([
                'ip_hash' => UsageLog::hashIp($ip),
                'created_at' => $age ?? now(),
                'updated_at' => $age ?? now(),
            ]);
        }
    }

    public function test_uploads_under_the_limit_are_accepted(): void
    {
        $this->seedUploads(2);

        $this->postJson(route('voice-notes.store'), ['file' => $this->audio()])
            ->assertCreated();
    }

    public function test_the_api_refuses_once_the_hourly_limit_is_reached(): void
    {
        $this->seedUploads(3);

        $this->postJson(route('voice-notes.store'), ['file' => $this->audio()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        Bus::assertNothingDispatched();
    }

    /** A generic "too many requests" tells the reader nothing actionable. */
    public function test_the_refusal_says_what_happened_and_when_to_come_back(): void
    {
        $this->seedUploads(3);

        $response = $this->postJson(route('voice-notes.store'), ['file' => $this->audio()]);

        $message = $response->json('errors.file.0');

        $this->assertStringContainsString('3 notes in the last hour', $message);
        $this->assertStringContainsString('Try again in', $message);
    }

    public function test_the_web_form_is_throttled_the_same_way(): void
    {
        $this->seedUploads(3);

        Livewire::test(UploadNote::class)
            ->set('file', $this->audio())
            ->call('save')
            ->assertHasErrors('file');

        Bus::assertNothingDispatched();
    }

    public function test_uploads_older_than_an_hour_fall_out_of_the_window(): void
    {
        $this->seedUploads(5, age: now()->subMinutes(61));

        $this->postJson(route('voice-notes.store'), ['file' => $this->audio()])
            ->assertCreated();
    }

    public function test_another_ip_is_counted_separately(): void
    {
        $this->seedUploads(3, ip: '203.0.113.9');

        $this->postJson(route('voice-notes.store'), ['file' => $this->audio()])
            ->assertCreated();
    }

    /** Signed-in users are identifiable; the IP hash exists for people who are not. */
    public function test_a_signed_in_user_is_not_throttled_by_ip(): void
    {
        $this->seedUploads(10);

        $this->actingAs(User::factory()->create())
            ->postJson(route('voice-notes.store'), ['file' => $this->audio()])
            ->assertCreated();
    }

    public function test_the_raw_ip_is_never_stored(): void
    {
        $this->postJson(route('voice-notes.store'), ['file' => $this->audio()])->assertCreated();

        $this->assertDatabaseMissing('usage_logs', ['ip_hash' => '127.0.0.1']);
        $this->assertSame(64, strlen(UsageLog::latest('id')->first()->ip_hash));
    }
}
