<?php

namespace App\Contracts;

interface AudioInspector
{
    /**
     * Duration of an audio file in whole seconds, or null if it cannot be read.
     *
     * Used to enforce the duration limit on the ORIGINAL upload before any job
     * is dispatched. Implementations must not modify the file.
     */
    public function durationSeconds(string $absolutePath): ?int;
}
