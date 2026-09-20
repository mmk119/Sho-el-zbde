<div>
    <div class="mb-7">
        <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">
            Nobody has time for a nine minute voice note.
        </h1>
        <p class="mt-2 text-stone-600 dark:text-stone-400">
            Drop one in and get back the part you actually needed — the gist, what they
            asked you, and the details worth writing down.
        </p>
    </div>

    <form wire:submit="save" class="space-y-5">

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
                class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed px-6 py-10 text-center transition-colors"
                :class="over
                    ? 'border-stone-500 bg-stone-100 dark:border-stone-400 dark:bg-stone-800'
                    : 'border-stone-300 bg-white hover:bg-stone-50 dark:border-stone-700 dark:bg-stone-900 dark:hover:bg-stone-800/60'"
            >
                <svg class="mb-3 h-8 w-8 text-stone-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z"/>
                </svg>

                <span class="font-medium">Drop a voice note here</span>
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
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-stone-200 dark:bg-stone-800">
                    <div class="h-full w-1/3 animate-pulse rounded-full bg-stone-500"></div>
                </div>
                <p class="hint mt-1.5">Uploading…</p>
            </div>

            @if ($file && ! $errors->has('file'))
                <div class="mt-3 flex items-center gap-2 text-sm" wire:loading.remove wire:target="file">
                    <svg class="h-4 w-4 shrink-0 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                    </svg>
                    <span class="truncate font-medium">{{ $file->getClientOriginalName() }}</span>
                    <span class="hint shrink-0">{{ number_format($file->getSize() / 1048576, 1) }} MB</span>
                </div>
            @endif

            @error('file')
                <p class="mt-3 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300">
                    {{ $message }}
                </p>
            @enderror

            <p class="hint mt-3">
                {{ implode(', ', \App\Support\UploadRules::friendlyFormats()) }} ·
                up to {{ config('shoelzbde.max_upload_mb') }}MB ·
                up to {{ \App\Support\UploadRules::maxDurationMinutes() }} minutes
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
                <p class="hint mt-1.5">Auto detect works well. Set it if you already know.</p>
            </div>

            <div>
                <label for="hint" class="label">
                    Names and places <span class="font-normal text-stone-400">(optional)</span>
                </label>
                <input
                    id="hint"
                    type="text"
                    wire:model="prompt_hint"
                    class="field"
                    maxlength="500"
                    placeholder="Teta Mariam, Jounieh, Achrafieh"
                >
                <p class="hint mt-1.5">Helps get proper nouns right. It's the single biggest win.</p>
            </div>
        </div>

        <div class="flex items-center gap-3 pt-1">
            <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save, file">
                <span wire:loading.remove wire:target="save">Get the gist</span>
                <span wire:loading wire:target="save">Starting…</span>
            </button>
            <span class="hint" wire:loading.remove wire:target="save">Usually under a minute.</span>
        </div>
    </form>
</div>
