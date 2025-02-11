<?php declare(strict_types=1);

namespace Nadybot\Core\Exceptions;

use Exception;
use Psr\SimpleCache\InvalidArgumentException;

class InvalidCacheKeyException extends Exception implements InvalidArgumentException {
}
