<?php declare(strict_types=1);

namespace Nadybot\Core\Exceptions;

use Exception;

/** Thrown when a given test file cannot be parsed */
class ParseTestException extends Exception implements TestingException {
}
