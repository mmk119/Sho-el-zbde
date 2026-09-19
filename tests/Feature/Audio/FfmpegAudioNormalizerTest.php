<?php

namespace Tests\Feature\Audio;

use App\Contracts\AudioInspector;
use App\Contracts\AudioNormalizer;
use App\Exceptions\AudioNormalizationFailed;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Exercises the real ffmpeg binary. Everything else in the suite fakes it; this
 * is the one place that proves the filter chain is actually valid, because a
 * typo in it would otherwise only surface in production.
 */
class FfmpegAudioNormalizerTest extends TestCase
{
    private array $scratch = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg is not on PATH.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->scratch as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function ffmpegAvailable(): bool
    {
        $process = new Process([config('shoelzbde.ffmpeg_path'), '-version']);
        $process->run();

        return $process->isSuccessful();
    }

    private function tempPath(string $extension): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'zbde-test-'.bin2hex(random_bytes(6)).'.'.$extension;
        $this->scratch[] = $path;

        return $path;
    }

    /** Stereo 44.1kHz tone with a long silence in the middle. */
    private function makeSource(): string
    {
        $path = $this->tempPath('wav');

        $process = new Process([
            config('shoelzbde.ffmpeg_path'),
            '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2',
            '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=stereo',
            '-f', 'lavfi', '-i', 'sine=frequency=880:duration=2',
            '-filter_complex', '[0:a]atrim=0:2[a];[1:a]atrim=0:6[b];[2:a]atrim=0:2[c];[a][b][c]concat=n=3:v=0:a=1[out]',
            '-map', '[out]', '-ac', '2', '-ar', '44100',
            $path,
        ]);
        $process->setTimeout(60);
        $process->run();

        $this->assertTrue($process->isSuccessful(), 'could not build the test audio');

        return $path;
    }

    public function test_it_produces_mono_16khz_pcm_s16le(): void
    {
        $target = $this->tempPath('wav');

        app(AudioNormalizer::class)->normalize($this->makeSource(), $target);

        $probe = new Process([
            config('shoelzbde.ffprobe_path'),
            '-v', 'error',
            '-show_entries', 'stream=channels,sample_rate,codec_name',
            '-of', 'default=noprint_wrappers=1',
            $target,
        ]);
        $probe->run();
        $out = $probe->getOutput();

        $this->assertStringContainsString('channels=1', $out);
        $this->assertStringContainsString('sample_rate=16000', $out);
        $this->assertStringContainsString('codec_name=pcm_s16le', $out);
    }

    /**
     * The reason normalized_duration_seconds exists at all: the filter chain
     * removes silence, so what gets sent is shorter than what was uploaded.
     */
    public function test_it_trims_long_silence_and_reports_the_shorter_duration(): void
    {
        $source = $this->makeSource();
        $target = $this->tempPath('wav');

        $original = app(AudioInspector::class)->durationSeconds($source);
        $normalized = app(AudioNormalizer::class)->normalize($source, $target);

        $this->assertSame(10, $original, 'test fixture should be 2s + 6s silence + 2s');
        $this->assertLessThan($original, $normalized->durationSeconds);
        $this->assertGreaterThan(0, $normalized->durationSeconds);
    }

    public function test_it_leaves_the_source_untouched(): void
    {
        $source = $this->makeSource();
        $before = md5_file($source);

        app(AudioNormalizer::class)->normalize($source, $this->tempPath('wav'));

        $this->assertSame($before, md5_file($source));
    }

    public function test_it_raises_a_typed_failure_on_unreadable_input(): void
    {
        $bogus = $this->tempPath('wav');
        file_put_contents($bogus, 'this is definitely not audio');

        $this->expectException(AudioNormalizationFailed::class);

        app(AudioNormalizer::class)->normalize($bogus, $this->tempPath('wav'));
    }

    public function test_a_missing_source_is_a_typed_failure(): void
    {
        $this->expectException(AudioNormalizationFailed::class);

        app(AudioNormalizer::class)->normalize('/nope/does-not-exist.wav', $this->tempPath('wav'));
    }
}
