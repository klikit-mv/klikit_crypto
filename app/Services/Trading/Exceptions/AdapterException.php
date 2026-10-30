<?php

namespace App\Services\Trading\Exceptions;

use RuntimeException;

/** The exchange/broker adapter failed to complete a request. */
class AdapterException extends RuntimeException {}
