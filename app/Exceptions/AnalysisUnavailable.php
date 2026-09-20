<?php

namespace App\Exceptions;

use RuntimeException;

/** Transient: network trouble, a 5xx, a timeout. Worth retrying. */
class AnalysisUnavailable extends RuntimeException
{
}
