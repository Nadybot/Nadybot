<?php declare(strict_types=1);

namespace Nadybot\Core\Exceptions;

use Exception;
use Psr\SimpleCache\InvalidArgumentException;

/** The chosen key is not usable as a cache key */
class InvalidCacheKeyException extends Exception implements InvalidArgumentException {
}
