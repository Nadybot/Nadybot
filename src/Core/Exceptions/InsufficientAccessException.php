<?php declare(strict_types=1);

namespace Nadybot\Core\Exceptions;

use Exception;

/** Someone tries to change a setting they don't have access to */
class InsufficientAccessException extends Exception {
}
