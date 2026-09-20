<?php

namespace App\Exceptions;

use RuntimeException;

/** Permanent: bad key, malformed request. Retrying will not help. */
class AnalysisRejected extends RuntimeException
{
}
