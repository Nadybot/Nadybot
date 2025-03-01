<?php declare(strict_types=1);

namespace Nadybot\Core\Exceptions;

use Exception;

/** Throw this exception to prevent further processing of the event */
class StopExecutionException extends Exception {
}
