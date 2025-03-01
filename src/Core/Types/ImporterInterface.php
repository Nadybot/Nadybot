<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use Nadybot\Core\DB;
use Psr\Log\LoggerInterface;

/**
 * This class provides a function to import exported data with
 * a standardized interface.
 */
interface ImporterInterface {
	/**
	 * Import the exported data back into the system
	 *
	 * @param list<object>              $data    The data to import, already proper classes
	 * @param array<string,AccessLevel> $rankMap A mapping of import rank to bot rank
	 */
	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void;
}
