<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Directory;

use Exception;

/**
 * Thrown by a provider when it cannot answer right now (timeout, server error).
 * This is different from "not found" (null), and makes the caller try the next provider.
 */
class DirectoryUnavailableException extends Exception {
}
