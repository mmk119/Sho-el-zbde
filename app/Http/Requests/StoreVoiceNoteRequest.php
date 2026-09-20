<?php

namespace App\Http\Requests;

use App\Contracts\AudioInspector;
use App\Support\UploadRules;
use App\Support\UploadThrottle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreVoiceNoteRequest extends FormRequest
{
    /** Set while validating so the controller does not have to probe twice. */
    public ?int $durationSeconds = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:' . UploadRules::maxKilobytes(),
                'mimetypes:' . implode(',', config('shoelzbde.accepted_mimes')),
            ],
            'language_hint' => ['nullable', 'string', 'max:16', 'regex:/^[a-zA-Z-]+$/'],

            // Names and places the uploader expects to hear. Appended to the
            // mandatory default prompt - optional for them, never optional for
            // the transcription call.
            'prompt_hint' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The duration limit is checked HERE, against the original upload, before
     * anything is stored or dispatched. Silence trimming in NormalizeAudio
     * shortens audio, so checking afterwards would let over-length notes through.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || ! $this->hasFile('file')) {
                    return;
                }

                if (UploadThrottle::exceeded($this->ip(), $this->user()?->id)) {
                    $validator->errors()->add('file', UploadThrottle::message($this->ip(), $this->user()?->id));

                    return;
                }

                $max = config('shoelzbde.max_duration_seconds');
                $seconds = app(AudioInspector::class)->durationSeconds(
                    $this->file('file')->getRealPath()
                );

                if ($seconds === null) {
                    $validator->errors()->add('file', 'We could not read this audio file. It may be corrupt.');

                    return;
                }

                if ($seconds > $max) {
                    $validator->errors()->add('file', sprintf(
                        'This note is %d minutes long. The limit is %d minutes.',
                        (int) ceil($seconds / 60),
                        (int) ($max / 60)
                    ));

                    return;
                }

                $this->durationSeconds = $seconds;
            },
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'That file is larger than the ' . UploadRules::maxMegabytes() . 'MB limit.',
            'file.mimetypes' => 'That does not look like an audio file we can read.',
        ];
    }
}
