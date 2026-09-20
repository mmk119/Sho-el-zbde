<div>
    <div class="mb-9">
        <h1 class="font-serif text-3xl leading-[1.15] tracking-tight text-stone-900 sm:text-[2.6rem] dark:text-stone-50">
            Nobody has time for a<br class="hidden sm:block"> nine minute voice note.
        </h1>
        <p class="mt-4 max-w-lg leading-relaxed text-stone-600 dark:text-stone-400">
            Drop one in and get back the part you actually needed: the gist, what they
            asked you, and the details worth writing down.
        </p>
    </div>

    <form wire:submit="save" class="space-y-6">

        {{-- Drag and drop, with the file input as the fallback underneath. --}}
        <div
            x-data="{
                over: false,
                pick(list) {
                    if (!list || !list.length) return;
                    const input = $refs.input;
                    const dt = new DataTransfer();
                    dt.items.add(list[0]);
                    input.files = dt.files;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                },
            }"
            @dragover.prevent="over = true"
            @dragleave.prevent="over = false"
            @drop.prevent="over = false; pick($event.dataTransfer.files)"
        >
            <label
                for="zbde-file"
                class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border border-dashed px-6 py-12 text-center transition-all duration-200"
                :class="over
                    ? 'border-accent-400 bg-accent-50 dark:border-accent-400 dark:bg-accent-900/20'
                    : 'border-stone-300 bg-white hover:border-accent-300 dark:border-stone-700 dark:bg-stone-900 dark:hover:border-accent-600'"
            >
                <svg class="mb-4 h-7 w-7 text-accent-500 dark:text-accent-400" fill="none" stroke="currentColor" stroke-width="1.4" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z"/>
                </svg>

                <span class="font-serif text-lg text-stone-800 dark:text-stone-200">Drop a voice note here</span>
                <span class="hint mt-1">or tap to choose a file</span>

                <input
                    id="zbde-file"
                    x-ref="input"
                    type="file"
                    wire:model="file"
                    accept="{{ \App\Support\UploadRules::acceptAttribute() }}"
                    class="sr-only"
                >
            </label>

            {{-- Upload progress: Livewire fires these on the file input. --}}
            <div wire:loading wire:target="file" class="mt-3">
                <div class="h-0.5 w-full overflow-hidden rounded-full bg-stone-200 dark:bg-stone-800">
                    <div class="h-full w-1/3 animate-pulse rounded-full bg-accent-400"></div>
                </div>
                <p class="hint mt-2">Uploading…</p>
            </div>

            @if ($file && ! $errors->has('file'))
                <div class="mt-3 flex items-center gap-2 text-sm animate-rise" wire:loading.remove wire:target="file">
                    <svg class="h-4 w-4 shrink-0 text-accent-500 dark:text-accent-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                    </svg>
                    <span class="truncate text-stone-700 dark:text-stone-300">{{ $file->getClientOriginalName() }}</span>
                    <span class="hint shrink-0">{{ number_format($file->getSize() / 1048576, 1) }} MB</span>
                </div>
            @endif

            @error('file')
                <p class="mt-3 rounded-xl border border-red-200 bg-red-50 px-3.5 py-2.5 text-sm leading-relaxed text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300">
                    {{ $message }}
                </p>
            @enderror

            <p class="hint mt-3">
                {{ implode(', ', \App\Support\UploadRules::friendlyFormats()) }}
                · up to {{ \App\Support\UploadRules::maxMegabytes() }}MB
                · up to {{ \App\Support\UploadRules::maxDurationMinutes() }} minutes
            </p>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="lang" class="label">Language</label>
                <select id="lang" wire:model="language_hint" class="field">
                    @foreach ($languages as $code => $name)
                        <option value="{{ $code }}">{{ $name }}</option>
                    @endforeach
                </select>
                <p class="hint mt-2">
                    Auto detect works well. Picking the language is what unlocks the
                    dialect vocabulary for Arabic.
                </p>
            </div>

            <div>
                <label for="hint" class="label">
                    Names and places <span class="font-normal text-stone-400">optional</span>
                </label>
                <input
                    id="hint"
                    type="text"
                    wire:model="prompt_hint"
                    class="field"
                    maxlength="500"
                    placeholder="Teta Mariam, Jounieh"
                >
                <p class="hint mt-2">Helps get proper nouns right, and it's the single biggest win.</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-4 pt-1">
            <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save, file">
                <span wire:loading.remove wire:target="save">Get the gist</span>
                <span wire:loading wire:target="save">Starting…</span>
            </button>
            <span class="hint" wire:loading.remove wire:target="save">Usually under a minute.</span>
        </div>
    </form>
</div>
