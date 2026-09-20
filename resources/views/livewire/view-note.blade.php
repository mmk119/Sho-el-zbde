@php
    use App\Enums\VoiceNoteStatus;

    $digest = $note->digest;
    $transcript = $note->transcript;
    $rtl = $this->isRtl();
    $dir = $rtl ? 'rtl' : 'ltr';
    $contentFont = $rtl ? 'rtl-content' : 'font-serif';

    $entityLabels = [
        'dates' => 'Dates',
        'times' => 'Times',
        'amounts' => 'Amounts',
        'names' => 'Names',
        'places' => 'Places',
    ];
@endphp

<div
    @if ($this->isWorking() && ! $note->isExpired())
        wire:poll.{{ config('shoelzbde.poll_interval_seconds') }}s="refreshNote"
    @endif
>

    {{-- ──────────────────────────────  EXPIRED  ────────────────────────────── --}}
    @if ($note->isExpired())

        <h1 class="font-serif text-[2rem] leading-tight tracking-tight text-sand-900 dark:text-sand-50">
            This one's gone
        </h1>

        <p class="mt-5 max-w-md leading-relaxed text-sand-500 dark:text-sand-400">
            Voice notes and their digests are deleted after
            {{ config('shoelzbde.retention_days') }} days. This one has passed that,
            so the audio, the transcript and the digest are no longer here.
        </p>

        <a href="{{ route('upload') }}" wire:navigate class="btn-quiet mt-8">Send a new one</a>

    {{-- ─────────────────────────────  PROCESSING  ───────────────────────────── --}}
    @elseif ($note->status !== VoiceNoteStatus::Done && $note->status !== VoiceNoteStatus::Failed)

        <div class="mb-10">
            <h1 class="font-serif text-[2rem] leading-tight tracking-tight text-sand-900 dark:text-sand-50">
                Working on it
            </h1>
            <p class="hint mt-2.5 truncate">{{ $note->original_filename }}</p>
        </div>

        @php
            $steps = $this->steps();
            $completed = collect($steps)->filter(fn ($s) => $s['state'] === 'complete')->count();
            // The rail fills to the step that is running, so it reads as progress
            // rather than as four disconnected dots.
            $fill = count($steps) > 1 ? min(100, ($completed / (count($steps) - 1)) * 100) : 0;
        @endphp

        <ol class="relative">
            {{-- Rail behind the markers --}}
            <div class="absolute start-[11px] top-5 bottom-5 w-px -translate-x-1/2 bg-sand-200 rtl:translate-x-1/2 dark:bg-sand-800" aria-hidden="true"></div>
            <div
                class="absolute start-[11px] top-5 w-px -translate-x-1/2 bg-accent-400 transition-all duration-700 ease-out rtl:translate-x-1/2"
                style="height: calc((100% - 2.5rem) * {{ $fill / 100 }});"
                aria-hidden="true"
            ></div>

            @foreach ($steps as $i => $step)
                <li wire:key="step-{{ $i }}" class="relative flex items-center gap-4 py-3.5">
                    <span class="step-marker step-{{ $step['state'] }}">
                        @if ($step['state'] === 'complete')
                            <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                            </svg>
                        @elseif ($step['state'] === 'active')
                            <span class="block h-1.5 w-1.5 animate-pulse rounded-full bg-current"></span>
                        @elseif ($step['state'] === 'failed')
                            <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                            </svg>
                        @endif
                    </span>

                    <span class="min-w-0">
                        <span class="block text-[15px] transition-colors duration-500
                            {{ $step['state'] === 'pending'
                                ? 'text-sand-300 dark:text-sand-700'
                                : 'text-sand-800 dark:text-sand-200' }}">
                            {{ $step['label'] }}
                        </span>
                        @if ($step['state'] === 'active')
                            <span class="hint animate-rise mt-0.5 block">{{ $step['note'] }}</span>
                        @endif
                    </span>
                </li>
            @endforeach
        </ol>

        <p class="hint mt-8">{{ $this->elapsedSeconds() }}s elapsed · this page updates itself</p>

    {{-- ──────────────────────────────  FAILED  ─────────────────────────────── --}}
    @elseif ($note->status === VoiceNoteStatus::Failed)

        <div class="mb-8">
            <h1 class="font-serif text-[2rem] leading-tight tracking-tight text-sand-900 dark:text-sand-50">
                This one didn't work out
            </h1>
            <p class="hint mt-2.5 truncate">{{ $note->original_filename }}</p>
        </div>

        <div class="panel-accent">
            <p class="leading-relaxed text-accent-800 dark:text-accent-100">
                {{ $note->error_message ?? 'Something went wrong while processing this note.' }}
            </p>
        </div>

        {{-- A failed digest does not mean a lost transcript. --}}
        @if ($transcript)
            <div class="mt-10">
                <p class="eyebrow mb-4">Transcript</p>
                <p dir="{{ $dir }}" class="{{ $contentFont }} text-[17px] leading-relaxed whitespace-pre-wrap text-sand-600 dark:text-sand-400">{{ $transcript->full_text }}</p>
            </div>
        @endif

        <a href="{{ route('upload') }}" wire:navigate class="btn-quiet mt-10">Try another one</a>

    {{-- ──────────────────────────────  RESULT  ─────────────────────────────── --}}
    @else

        {{-- Metadata, kept small. The digest is the headline, not the filename. --}}
        <div class="mb-8 flex flex-wrap items-center gap-x-2.5 gap-y-2">
            @if ($digest)
                @php $urgency = $digest->urgency->value; @endphp
                <span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[10.5px] font-semibold tracking-[0.12em] uppercase
                    {{ $urgency === 'high'
                        ? 'bg-accent-400 text-sand-950'
                        : 'bg-sand-100 text-sand-400 dark:bg-sand-800 dark:text-sand-500' }}">
                    {{ ucfirst($urgency) }}
                    {{-- The badge reads as one word visually; say what it means for screen readers. --}}
                    <span class="sr-only">urgency</span>
                </span>
            @endif

            <span class="hint truncate">{{ $note->original_filename }}</span>

            <span class="hint">
                @if ($note->language_detected)· {{ ucfirst($note->language_detected) }}@endif
                @if ($note->duration_seconds)
                    · {{ intdiv($note->duration_seconds, 60) }}:{{ str_pad($note->duration_seconds % 60, 2, '0', STR_PAD_LEFT) }}
                @endif
                @if ($transcript)· {{ number_format($transcript->word_count) }} words @endif
            </span>
        </div>

        @if ($digest)
            {{-- ───── The hero: the gist, then what they asked you ───── --}}

            <section class="mb-12">
                <p class="eyebrow mb-5">El Zbde</p>
                <ul class="space-y-5" dir="{{ $dir }}">
                    @foreach ($digest->summary as $line)
                        <li class="{{ $contentFont }} text-[1.35rem] leading-[1.45] text-sand-900 sm:text-[1.55rem] dark:text-sand-50">
                            {{ $line }}
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="panel-accent mb-12">
                <p class="eyebrow mb-4 text-accent-600 dark:text-accent-400">Questions for you</p>

                @if (count($digest->questions))
                    <ul class="space-y-4" dir="{{ $dir }}">
                        @foreach ($digest->questions as $question)
                            <li class="{{ $contentFont }} text-[1.15rem] leading-snug text-accent-900 sm:text-[1.3rem] dark:text-accent-50">
                                {{ $question }}
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-accent-700/60 dark:text-accent-200/50">
                        Nothing to answer. They didn't ask you anything directly.
                    </p>
                @endif
            </section>

            {{-- ───── Everything below is secondary ───── --}}

            <audio controls preload="none" class="player mb-12" src="{{ route('voice-notes.audio', $note->public_token) }}">
                Your browser can't play audio.
            </audio>

            @php
                $entities = collect($digest->entities ?? [])->filter(fn ($v) => is_array($v) && count($v));
            @endphp

            @if ($entities->isNotEmpty())
                <section class="mb-10">
                    <p class="eyebrow mb-5">Key details</p>
                    <div class="space-y-4">
                        @foreach ($entities as $type => $values)
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-baseline sm:gap-5">
                                <p class="hint w-16 shrink-0">{{ $entityLabels[$type] ?? ucfirst($type) }}</p>
                                <div class="flex flex-wrap gap-1.5" dir="{{ $dir }}">
                                    @foreach ($values as $value)
                                        <span class="chip">{{ $value }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if (count($digest->action_items))
                <section
                    class="mb-10"
                    x-data="{
                        key: 'zbde-todo-{{ $note->public_token }}',
                        done: {},
                        init() {
                            try { this.done = JSON.parse(localStorage.getItem(this.key)) || {}; } catch (e) { this.done = {}; }
                        },
                        toggle(i) {
                            this.done[i] = !this.done[i];
                            try { localStorage.setItem(this.key, JSON.stringify(this.done)); } catch (e) {}
                        },
                    }"
                >
                    <p class="eyebrow mb-5">Things to do</p>
                    <ul class="space-y-3.5" dir="{{ $dir }}">
                        @foreach ($digest->action_items as $i => $item)
                            <li>
                                <label class="flex cursor-pointer items-start gap-3">
                                    <input
                                        type="checkbox"
                                        class="mt-1 h-4 w-4 shrink-0 rounded border-sand-300 text-accent-500 focus:ring-accent-400 dark:border-sand-600 dark:bg-sand-800"
                                        :checked="done[{{ $i }}]"
                                        @change="toggle({{ $i }})"
                                    >
                                    <span class="text-[15px] leading-relaxed transition-colors duration-200"
                                          :class="done[{{ $i }}] ? 'line-through text-sand-300 dark:text-sand-700' : 'text-sand-700 dark:text-sand-300'">
                                        {{ $item }}
                                    </span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                    <p class="hint mt-4">Ticks are saved in this browser only.</p>
                </section>
            @endif

            {{--
                Unclear passages. Deliberately quiet: small, secondary, never an
                error state. A digest with notes is still a good digest, and the
                transcript is right there to check against.
            --}}
            @if (count($digest->notes ?? []))
                <details class="group mb-10">
                    <summary class="hint flex cursor-pointer list-none items-center gap-1.5 select-none">
                        <svg class="h-3 w-3 transition-transform duration-200 group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/>
                        </svg>
                        Some parts were unclear ({{ count($digest->notes) }})
                    </summary>
                    <ul class="animate-rise mt-3 space-y-1.5 ps-[18px]">
                        @foreach ($digest->notes as $unclear)
                            <li class="text-xs break-words text-sand-300 dark:text-sand-700" dir="{{ $dir }}">{{ $unclear }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif

            @if ($transcript)
                <section
                    class="mb-10"
                    x-data="{
                        open: false,
                        copied: false,
                        copy() {
                            navigator.clipboard.writeText($refs.body.innerText).then(() => {
                                this.copied = true;
                                setTimeout(() => this.copied = false, 1800);
                            });
                        },
                    }"
                >
                    <div class="flex items-center justify-between gap-3">
                        <button type="button" @click="open = !open" class="eyebrow flex items-center gap-2 transition-colors hover:text-sand-600 dark:hover:text-sand-300">
                            <svg class="h-3 w-3 transition-transform duration-200" :class="open && 'rotate-90'" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/>
                            </svg>
                            Full transcript
                        </button>

                        <button type="button" @click="copy()" class="btn-quiet px-2.5 py-1 text-xs">
                            <span x-show="!copied">Copy</span>
                            <span x-show="copied" x-cloak class="text-accent-600 dark:text-accent-400">Copied</span>
                        </button>
                    </div>

                    <div x-show="open" x-collapse x-cloak>
                        <p x-ref="body" dir="{{ $dir }}"
                           class="{{ $contentFont }} mt-5 text-[17px] leading-relaxed whitespace-pre-wrap text-sand-600 dark:text-sand-400">{{ $transcript->full_text }}</p>
                    </div>
                </section>
            @endif

            <div
                class="flex flex-wrap gap-2.5"
                x-data="{
                    copiedDigest: false,
                    copiedLink: false,
                    copyDigest() {
                        navigator.clipboard.writeText(@js($this->plainTextDigest())).then(() => {
                            this.copiedDigest = true;
                            setTimeout(() => this.copiedDigest = false, 1800);
                        });
                    },
                    copyLink() {
                        navigator.clipboard.writeText(window.location.href).then(() => {
                            this.copiedLink = true;
                            setTimeout(() => this.copiedLink = false, 1800);
                        });
                    },
                }"
            >
                <button type="button" @click="copyDigest()" class="btn-quiet">
                    <span x-show="!copiedDigest">Copy digest</span>
                    <span x-show="copiedDigest" x-cloak class="text-accent-600 dark:text-accent-400">Copied</span>
                </button>

                <button type="button" @click="copyLink()" class="btn-quiet">
                    <span x-show="!copiedLink">Share link</span>
                    <span x-show="copiedLink" x-cloak class="text-accent-600 dark:text-accent-400">Link copied</span>
                </button>

                <a href="{{ route('upload') }}" wire:navigate class="btn-quiet">Another one</a>
            </div>
        @else
            <div class="panel">
                <p class="text-sand-600 dark:text-sand-400">
                    This note finished without a digest. The transcript is below if there is one.
                </p>
            </div>

            @if ($transcript)
                <p dir="{{ $dir }}" class="{{ $contentFont }} mt-8 text-[17px] leading-relaxed whitespace-pre-wrap text-sand-600 dark:text-sand-400">{{ $transcript->full_text }}</p>
            @endif
        @endif
    @endif
</div>
