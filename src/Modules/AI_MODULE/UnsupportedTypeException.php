<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE;

/**
 * Thrown when a PHP parameter type cannot be mapped to a JSON Schema type
 * for AI function registration.
 */
class UnsupportedTypeException extends \Exception {
}
