<?php

namespace App\Exceptions;

use RuntimeException;

/** The provider asked us to slow down. Not a failure - the job releases itself. */
class AnalysisRateLimited extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds, string $message = 'Rate limited')
    {
        parent::__construct($message);
    }
}
