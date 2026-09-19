<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Permanent: a bad key, a malformed request, an unreadable file. Retrying an
 * expired API key for thirty minutes helps nobody, so jobs fail fast on this.
 */
class TranscriptionRejected extends RuntimeException
{
}
