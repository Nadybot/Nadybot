<?php declare(strict_types=1);

namespace Nadybot\Core\Exceptions;

use Exception;

/** Thrown when a given test file doesn't exist */
class NonExistingTestException extends Exception implements TestingException {
}
