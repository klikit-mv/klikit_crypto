<?php

namespace App\Services\Signals;

use RuntimeException;

/** The message could not be turned into a trade without guessing; it is rejected. */
class AmbiguousSignal extends RuntimeException {}
