#!/usr/bin/env php
<?php
/**
 * Phase 0 - Whisper quality probe for "Sho el Zbde".
 *
 * Standalone. No framework, no composer, no autoloader. The point is to find out
 * how Whisper handles real Lebanese Arabic voice notes BEFORE we commit to it as
 * the transcription backend. Everything here is throwaway; the real pipeline
 * lives behind the TranscriptionService interface from Phase 2 onward.
 *
 * Usage:
 *   php transcribe-test.php samples/teta.m4a
 *   php transcribe-test.php --language=ar samples/*.m4a
 *   php transcribe-test.php --model=gpt-4o-transcribe --save samples/teta.m4a
 *
 * Options:
 *   --language=xx    ISO-639-1 hint. Omit for auto detect (the app default).
 *   --model=NAME     Default: TRANSCRIPTION_MODEL from .env, else whisper-1.
 *   --prompt="..."   Whisper prompt, for nudging spelling of names and places.
 *   --no-normalize   Send the original file instead of the ffmpeg-normalized wav.
 *   --save           Write the full result JSON into out/. Off by default:
 *                    transcripts are private messages and we do not spill them.
 *   --quiet          Print only the transcript.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(1);
}

const WHISPER_PER_MINUTE_USD = 0.006; // whisper-1 list price, for rough cost math

// ---------------------------------------------------------------------------
// Tiny .env reader. Same contract as config() later: nothing hardcoded here.
// ---------------------------------------------------------------------------

function env_load(string $path): array
{
    if (! is_file($path)) {
        return [];
    }

    $vars = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);

        if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'") && $value[0] === substr($value, -1)) {
            $value = substr($value, 1, -1);
        }

        $vars[trim($key)] = $value;
    }

    return $vars;
}

function env_get(array $vars, string $key, ?string $default = null): ?string
{
    $value = $vars[$key] ?? (getenv($key) ?: null);

    return ($value === null || $value === '') ? $default : $value;
}

// ---------------------------------------------------------------------------
// Output helpers
// ---------------------------------------------------------------------------

$quiet = false;

function out(string $line = ''): void
{
    global $quiet;

    if (! $quiet) {
        fwrite(STDOUT, $line . "\n");
    }
}

function fail(string $message): void
{
    fwrite(STDERR, "\n  ERROR  " . $message . "\n\n");
    exit(1);
}

function human_bytes(int $bytes): string
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

function human_duration(float $seconds): string
{
    return sprintf('%d:%02d', (int) ($seconds / 60), (int) $seconds % 60);
}

/** True for scripts we need to render right-to-left on the result page. */
function is_rtl(string $text): bool
{
    return (bool) preg_match('/[\x{0590}-\x{05FF}\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text);
}

// ---------------------------------------------------------------------------
// ffmpeg / ffprobe
// ---------------------------------------------------------------------------

function binary_exists(string $binary): bool
{
    $probe = PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v';
    exec(sprintf('%s %s 2>&1', $probe, escapeshellarg($binary)), $output, $code);

    return $code === 0;
}

function probe_duration(string $ffprobe, string $file): ?float
{
    $command = sprintf(
        '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
        escapeshellarg($ffprobe),
        escapeshellarg($file)
    );

    exec($command, $lines, $code);

    if ($code !== 0 || empty($lines[0]) || ! is_numeric(trim($lines[0]))) {
        return null;
    }

    return (float) trim($lines[0]);
}

/**
 * Mono, 16kHz, 16-bit wav with long silences trimmed - the same normalization
 * NormalizeAudio will do in Phase 2. Doing it here means the Phase 0 numbers
 * reflect what the real pipeline will actually send to Whisper.
 */
function normalize_audio(string $ffmpeg, string $source, string $target, int $rate, int $channels): void
{
    $filter = 'silenceremove=stop_periods=-1:stop_duration=1.5:stop_threshold=-40dB';

    $command = sprintf(
        '%s -hide_banner -loglevel error -y -i %s -ac %d -ar %d -c:a pcm_s16le -af %s %s 2>&1',
        escapeshellarg($ffmpeg),
        escapeshellarg($source),
        $channels,
        $rate,
        escapeshellarg($filter),
        escapeshellarg($target)
    );

    exec($command, $output, $code);

    if ($code !== 0 || ! is_file($target)) {
        fail("ffmpeg failed to normalize the audio:\n         " . implode("\n         ", $output));
    }
}

// ---------------------------------------------------------------------------
// Whisper call
// ---------------------------------------------------------------------------

function transcribe(string $baseUrl, string $apiKey, string $file, string $model, ?string $language, ?string $prompt): array
{
    // verbose_json gives us segments and the detected language. The gpt-4o
    // transcription models only speak plain json, so we ask for less from them.
    $supportsVerbose = str_starts_with($model, 'whisper');

    $fields = [
        'file' => new CURLFile($file, 'application/octet-stream', basename($file)),
        'model' => $model,
        'response_format' => $supportsVerbose ? 'verbose_json' : 'json',
    ];

    if ($language !== null) {
        $fields['language'] = $language;
    }

    if ($prompt !== null) {
        $fields['prompt'] = $prompt;
    }

    $curl = curl_init(rtrim($baseUrl, '/') . '/audio/transcriptions');

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);

    $started = microtime(true);
    $body = curl_exec($curl);
    $elapsed = microtime(true) - $started;
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);

    curl_close($curl);

    if ($body === false) {
        fail('Request to the transcription API failed: ' . $error);
    }

    $decoded = json_decode((string) $body, true);

    if ($status !== 200) {
        $message = $decoded['error']['message'] ?? substr((string) $body, 0, 400);
        fail("Transcription API returned HTTP {$status}: {$message}");
    }

    if (! is_array($decoded) || ! isset($decoded['text'])) {
        fail('Transcription API returned something that was not a transcript.');
    }

    $decoded['_elapsed'] = $elapsed;

    return $decoded;
}

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

$env = env_load(__DIR__ . '/.env');

$language = null;
$prompt = null;
$model = env_get($env, 'TRANSCRIPTION_MODEL', 'whisper-1');
$normalize = true;
$save = false;
$files = [];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--language=')) {
        $language = substr($arg, 11);
    } elseif (str_starts_with($arg, '--model=')) {
        $model = substr($arg, 8);
    } elseif (str_starts_with($arg, '--prompt=')) {
        $prompt = substr($arg, 9);
    } elseif ($arg === '--no-normalize') {
        $normalize = false;
    } elseif ($arg === '--save') {
        $save = true;
    } elseif ($arg === '--quiet') {
        $quiet = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, (string) file_get_contents(__FILE__, false, null, 0, 1500));
        exit(0);
    } elseif (str_starts_with($arg, '-')) {
        fail("Unknown option: {$arg}");
    } else {
        $matches = glob($arg);
        $files = array_merge($files, ($matches === false || $matches === []) ? [$arg] : $matches);
    }
}

if ($files === []) {
    fail("No audio file given.\n         Usage: php transcribe-test.php [--language=ar] [--save] <file>...");
}

if (! extension_loaded('curl')) {
    fail('The php-curl extension is required.');
}

$apiKey = env_get($env, 'OPENAI_API_KEY');

if ($apiKey === null) {
    fail('OPENAI_API_KEY is not set. Copy .env.example to .env and fill it in.');
}

$baseUrl = env_get($env, 'OPENAI_BASE_URL', 'https://api.openai.com/v1');
$ffmpeg = env_get($env, 'FFMPEG_PATH', 'ffmpeg');
$ffprobe = env_get($env, 'FFPROBE_PATH', 'ffprobe');
$rate = (int) env_get($env, 'AUDIO_TARGET_SAMPLE_RATE', '16000');
$channels = (int) env_get($env, 'AUDIO_TARGET_CHANNELS', '1');

$haveFfmpeg = binary_exists($ffmpeg);
$haveFfprobe = binary_exists($ffprobe);

if ($normalize && ! $haveFfmpeg) {
    out('  note   ffmpeg not found on PATH - sending the original file instead.');
    out('         Install ffmpeg for a like-for-like test of the real pipeline.');
    out();
    $normalize = false;
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

$totalCost = 0.0;
$temporary = [];

register_shutdown_function(function () use (&$temporary) {
    foreach ($temporary as $path) {
        @unlink($path);
    }
});

foreach ($files as $file) {
    if (! is_file($file)) {
        fwrite(STDERR, "  skip   {$file} - not a file\n");
        continue;
    }

    $originalSize = (int) filesize($file);
    $duration = $haveFfprobe ? probe_duration($ffprobe, $file) : null;

    out(str_repeat('=', 72));
    out('  ' . basename($file));
    out(str_repeat('=', 72));
    out(sprintf(
        '  source     %s%s',
        human_bytes($originalSize),
        $duration !== null ? '  ·  ' . human_duration($duration) : ''
    ));

    $upload = $file;

    if ($normalize) {
        $upload = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zbde_' . bin2hex(random_bytes(6)) . '.wav';
        $temporary[] = $upload;

        $normalizeStarted = microtime(true);
        normalize_audio($ffmpeg, $file, $upload, $rate, $channels);
        $normalizeElapsed = microtime(true) - $normalizeStarted;

        $trimmed = $haveFfprobe ? probe_duration($ffprobe, $upload) : null;

        out(sprintf(
            '  normalized %s  ·  %dHz mono%s  ·  %.1fs',
            human_bytes((int) filesize($upload)),
            $rate,
            $trimmed !== null ? '  ·  ' . human_duration($trimmed) . ' after silence trim' : '',
            $normalizeElapsed
        ));
    }

    out(sprintf('  model      %s  ·  language hint: %s', $model, $language ?? 'auto detect'));
    out('  ...');

    $result = transcribe($baseUrl, $apiKey, $upload, $model, $language, $prompt);

    $text = trim((string) $result['text']);
    $detected = $result['language'] ?? 'not reported';
    $billable = $result['duration'] ?? $duration;
    $cost = $billable !== null ? ($billable / 60) * WHISPER_PER_MINUTE_USD : null;
    $totalCost += $cost ?? 0.0;

    $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $wordCount = is_array($words) ? count($words) : 0;

    out();
    out(sprintf(
        '  detected   %s%s',
        $detected,
        is_rtl($text) ? '  (RTL - result page must flip direction)' : ''
    ));
    out(sprintf(
        '  took       %.1fs%s',
        $result['_elapsed'],
        $billable ? sprintf('  (%.1fx realtime)', $billable / max($result['_elapsed'], 0.001)) : ''
    ));
    out(sprintf(
        '  words      %d  ·  segments: %s',
        $wordCount,
        isset($result['segments']) ? (string) count($result['segments']) : 'n/a'
    ));

    if ($cost !== null) {
        out(sprintf('  cost       ~$%.4f', $cost));
    }

    out();
    out(str_repeat('-', 72));

    if ($quiet) {
        fwrite(STDOUT, $text . "\n");
    } else {
        out($text);
        out(str_repeat('-', 72));
        out();
    }

    if ($save) {
        @mkdir(__DIR__ . '/out', 0775, true);

        $path = sprintf('%s/out/%s-%s.json', __DIR__, pathinfo($file, PATHINFO_FILENAME), date('Ymd-His'));
        unset($result['_elapsed']);
        file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        out("  saved      {$path}  (gitignored - do not commit)");
        out();
    }
}

if (count($files) > 1 && $totalCost > 0) {
    out(sprintf('  total cost ~$%.4f across %d files', $totalCost, count($files)));
    out();
}
