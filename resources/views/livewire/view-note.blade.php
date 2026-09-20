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

        <h1 class="mb-4 font-serif text-3xl tracking-tight text-stone-900 dark:text-stone-50">This one's gone</h1>

        <div class="card">
            <p class="leading-relaxed text-stone-600 dark:text-stone-400">
                Voice notes and their digests are deleted after
                {{ config('shoelzbde.retention_days') }} days. This one has passed that,
                so the audio, the transcript and the digest are no longer here.
            </p>
        </div>

        <a href="{{ route('upload') }}" wire:navigate class="btn-quiet mt-6">Send a new one</a>

    {{-- ─────────────────────────────  PROCESSING  ───────────────────────────── --}}
    @elseif ($note->status !== VoiceNoteStatus::Done && $note->status !== VoiceNoteStatus::Failed)

        <div class="mb-8">
            <h1 class="font-serif text-3xl tracking-tight text-stone-900 dark:text-stone-50">Working on it</h1>
            <p class="hint mt-2 truncate">{{ $note->original_filename }}</p>
        </div>

        @php
            $steps = $this->steps();
            $completed = collect($steps)->filter(fn ($s) => $s['state'] === 'complete')->count();
            // The rail fills to the midpoint of the active step, so it reads as
            // progress rather than as four disconnected dots.
            $fill = count($steps) > 1 ? min(100, ($completed / (count($steps) - 1)) * 100) : 0;
        @endphp

        <div class="card">
            <ol class="relative">
                {{-- Rail behind the markers --}}
                <div class="absolute start-[13px] top-4 bottom-4 w-0.5 -translate-x-1/2 rounded bg-stone-200 rtl:translate-x-1/2 dark:bg-stone-800" aria-hidden="true"></div>
                <div
                    class="absolute start-[13px] top-4 w-0.5 -translate-x-1/2 rounded bg-accent-400 transition-all duration-700 ease-out rtl:translate-x-1/2"
                    style="height: calc((100% - 2rem) * {{ $fill / 100 }});"
                    aria-hidden="true"
                ></div>

                @foreach ($steps as $i => $step)
                    <li wire:key="step-{{ $i }}" class="relative flex items-start gap-4 py-2.5">
                        <span class="step-marker step-{{ $step['state'] }}">
                            @if ($step['state'] === 'complete')
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.6" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                                </svg>
                            @elseif ($step['state'] === 'active')
                                <span class="block h-2 w-2 animate-pulse rounded-full bg-current"></span>
                            @elseif ($step['state'] === 'failed')
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.6" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                                </svg>
                            @endif
                        </span>

                        <span class="min-w-0 pt-0.5">
                            <span class="block text-sm transition-colors duration-500
                                {{ $step['state'] === 'pending'
                                    ? 'text-stone-400 dark:text-stone-600'
                                    : 'font-medium text-stone-800 dark:text-stone-200' }}">
                                {{ $step['label'] }}
                            </span>
                            @if ($step['state'] === 'active')
                                <span class="hint animate-rise mt-0.5 block">{{ $step['note'] }}</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ol>
        </div>

        <p class="hint mt-5">
            {{ $this->elapsedSeconds() }}s elapsed · this page updates itself
        </p>

    {{-- ──────────────────────────────  FAILED  ─────────────────────────────── --}}
    @elseif ($note->status === VoiceNoteStatus::Failed)

        <div class="mb-6">
            <h1 class="font-serif text-3xl tracking-tight text-stone-900 dark:text-stone-50">This one didn't work out</h1>
            <p class="hint mt-2 truncate">{{ $note->original_filename }}</p>
        </div>

        <div class="surface border-accent-200 bg-accent-50 p-5 sm:p-6 dark:border-accent-800/60 dark:bg-accent-900/15">
            <p class="leading-relaxed text-accent-900 dark:text-accent-100">
                {{ $note->error_message ?? 'Something went wrong while processing this note.' }}
            </p>
        </div>

        {{-- A failed digest does not mean a lost transcript. --}}
        @if ($transcript)
            <div class="mt-6">
                <p class="eyebrow mb-3">Transcript</p>
                <div class="card" dir="{{ $dir }}">
                    <p class="{{ $contentFont }} text-[17px] leading-relaxed whitespace-pre-wrap text-stone-700 dark:text-stone-300">{{ $transcript->full_text }}</p>
                </div>
            </div>
        @endif

        <a href="{{ route('upload') }}" wire:navigate class="btn-quiet mt-6">Try another one</a>

    {{-- ──────────────────────────────  RESULT  ─────────────────────────────── --}}
    @else

        {{-- Metadata first, kept small: the digest is the headline, not the filename. --}}
        <div class="mb-7 flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
            @if ($digest)
                @php $urgency = $digest->urgency->value; @endphp
                <span class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-[11px] font-semibold tracking-[0.1em] uppercase
                    {{ $urgency === 'high'
                        ? 'bg-accent-400 text-stone-950'
                        : 'border border-stone-200 text-stone-500 dark:border-stone-700 dark:text-stone-400' }}">
                    @if ($urgency === 'high')
                        <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                            <path d="M10 2a1 1 0 0 1 1 1v8a1 1 0 1 1-2 0V3a1 1 0 0 1 1-1Zm0 13.5a1.25 1.25 0 1 1 0 2.5 1.25 1.25 0 0 1 0-2.5Z"/>
                        </svg>
                    @endif
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

            <section class="mb-6">
                <p class="eyebrow mb-4">El Zbde</p>
                <ul class="space-y-4" dir="{{ $dir }}">
                    @foreach ($digest->summary as $line)
                        <li class="{{ $contentFont }} text-xl leading-snug text-stone-900 sm:text-[1.6rem] sm:leading-[1.4] dark:text-stone-50">
                            {{ $line }}
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="surface mb-8 border-accent-300 bg-accent-50/70 p-5 sm:p-6 dark:border-accent-700/60 dark:bg-accent-900/15">
                <p class="eyebrow mb-3 text-accent-700 dark:text-accent-400">Questions for you</p>

                @if (count($digest->questions))
                    <ul class="space-y-3.5" dir="{{ $dir }}">
                        @foreach ($digest->questions as $question)
                            <li class="{{ $contentFont }} text-lg leading-snug text-accent-950 sm:text-xl dark:text-accent-50">
                                {{ $question }}
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-accent-800/70 dark:text-accent-200/60">
                        Nothing to answer. They didn't ask you anything directly.
                    </p>
                @endif
            </section>

            {{-- ───── Everything below is secondary ───── --}}

            <audio controls preload="none" class="mb-6 w-full" src="{{ route('voice-notes.audio', $note->public_token) }}">
                Your browser can't play audio.
            </audio>

            @php
                $entities = collect($digest->entities ?? [])->filter(fn ($v) => is_array($v) && count($v));
            @endphp

            @if ($entities->isNotEmpty())
                <section class="card mb-4">
                    <p class="eyebrow mb-4">Key details</p>
                    <div class="space-y-3.5">
                        @foreach ($entities as $type => $values)
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-baseline sm:gap-4">
                                <p class="hint w-20 shrink-0">{{ $entityLabels[$type] ?? ucfirst($type) }}</p>
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
                    class="card mb-4"
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
                    <p class="eyebrow mb-4">Things to do</p>
                    <ul class="space-y-3" dir="{{ $dir }}">
                        @foreach ($digest->action_items as $i => $item)
                            <li>
                                <label class="flex cursor-pointer items-start gap-3">
                                    <input
                                        type="checkbox"
                                        class="mt-1 h-4 w-4 shrink-0 rounded border-stone-300 text-accent-500 focus:ring-accent-400 dark:border-stone-600 dark:bg-stone-800"
                                        :checked="done[{{ $i }}]"
                                        @change="toggle({{ $i }})"
                                    >
                                    <span class="text-[15px] leading-relaxed transition-colors duration-200"
                                          :class="done[{{ $i }}] ? 'line-through text-stone-400 dark:text-stone-600' : 'text-stone-700 dark:text-stone-300'">
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
                <details class="group mb-4 px-1">
                    <summary class="hint flex cursor-pointer list-none items-center gap-1.5 select-none">
                        <svg class="h-3 w-3 transition-transform duration-200 group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/>
                        </svg>
                        Some parts were unclear ({{ count($digest->notes) }})
                    </summary>
                    <ul class="animate-rise mt-2.5 space-y-1.5 ps-[18px]">
                        @foreach ($digest->notes as $unclear)
                            <li class="text-xs break-words text-stone-400 dark:text-stone-600" dir="{{ $dir }}">{{ $unclear }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif

            @if ($transcript)
                <section
                    class="card"
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
                        <button type="button" @click="open = !open" class="eyebrow flex items-center gap-2 hover:text-stone-600 dark:hover:text-stone-300">
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
                           class="{{ $contentFont }} mt-5 text-[17px] leading-relaxed whitespace-pre-wrap text-stone-700 dark:text-stone-300">{{ $transcript->full_text }}</p>
                    </div>
                </section>
            @endif

            <div
                class="mt-7 flex flex-wrap gap-2.5"
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
            <div class="card">
                <p class="text-stone-600 dark:text-stone-400">
                    This note finished without a digest. The transcript is below if there is one.
                </p>
            </div>

            @if ($transcript)
                <div class="card mt-5" dir="{{ $dir }}">
                    <p class="{{ $contentFont }} text-[17px] leading-relaxed whitespace-pre-wrap text-stone-700 dark:text-stone-300">{{ $transcript->full_text }}</p>
                </div>
            @endif
        @endif
    @endif
</div>
