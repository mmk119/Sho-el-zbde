<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The provider asked us to slow down. This is not a failure - the job releases
 * itself back onto the queue and does not spend part of its error budget.
 */
class TranscriptionRateLimited extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds, string $message = 'Rate limited')
    {
        parent::__construct($message);
    }
}
