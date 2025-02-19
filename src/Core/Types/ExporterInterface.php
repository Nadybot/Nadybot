<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use Nadybot\Core\DB;
use Psr\Log\LoggerInterface;

/**
 * This class provides a function to export its data with
 * a standardized interface
 */
interface ExporterInterface {
	/**
	 * Export data as a list of simple objects.
	 * For example, export all the alts of all characters in a specific format.
	 * An ImporterInterface should be able to import this data again.
	 *
	 * @return list<object> A list of the exported data
	 */
	public function export(DB $db, LoggerInterface $logger): array;
}
